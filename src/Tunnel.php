<?php

namespace ArtemYurov\Autossh;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
use ArtemYurov\Autossh\Services\ProcessManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * SSH Tunnel Manager
 *
 * Example usage:
 *
 * // Callback pattern (automatic tunnel closure)
 * Tunnel::connection('remote_db')
 *     ->withDatabaseConnection('pgsql_remote', [...])
 *     ->execute(function() {
 *         DB::connection('pgsql_remote')->select('...');
 *     });
 *
 * // Manual management
 * $tunnel = Tunnel::connection('remote_db')->start();
 * try {
 *     // ... work with tunnel
 * } finally {
 *     $tunnel->stop();
 * }
 */
class Tunnel
{
    protected ?TunnelConnection $connection = null;
    protected ?array $databaseConfig = null;
    protected ?string $connectionName = null;
    protected ?ProcessManager $processManager = null;

    public function __construct(
        protected readonly TunnelConfig $config
    ) {
    }

    /**
     * Get ProcessManager instance (lazy initialization)
     *
     * @return ProcessManager
     */
    protected function getProcessManager(): ProcessManager
    {
        if ($this->processManager === null) {
            $this->processManager = new ProcessManager();
        }

        return $this->processManager;
    }

    /**
     * Create manager from named connection in config
     *
     * @param string|null $name Connection name from config/tunnel.php
     * @return self
     * @throws \ArtemYurov\Autossh\Exceptions\TunnelConfigException
     */
    public static function connection(?string $name = null): self
    {
        $name = $name ?: config('tunnel.default');

        if (!$name) {
            throw new \ArtemYurov\Autossh\Exceptions\TunnelConfigException(
                'Tunnel connection name not specified and default connection not configured'
            );
        }

        $connectionConfig = config("tunnel.connections.{$name}");

        if (!$connectionConfig) {
            throw new \ArtemYurov\Autossh\Exceptions\TunnelConfigException(
                "Tunnel connection '{$name}' does not exist in configuration."
            );
        }

        // Validate required configuration parameters
        $requiredParams = ['user', 'host'];
        foreach ($requiredParams as $param) {
            if (empty($connectionConfig[$param])) {
                throw new \ArtemYurov\Autossh\Exceptions\TunnelConfigException(
                    "Required parameter '{$param}' is missing in tunnel connection '{$name}'"
                );
            }
        }

        // Parse tunnel type
        $type = \ArtemYurov\Autossh\Enums\TunnelType::Forward;
        if (isset($connectionConfig['type'])) {
            $type = \ArtemYurov\Autossh\Enums\TunnelType::fromString($connectionConfig['type']);
        }

        $config = new TunnelConfig(
            type: $type,
            user: $connectionConfig['user'],
            host: $connectionConfig['host'],
            port: $connectionConfig['port'] ?? 22,
            identityFile: $connectionConfig['identity_file'] ?? null,
            remoteHost: $connectionConfig['remote_host'] ?? 'localhost',
            remotePort: $connectionConfig['remote_port'] ?? 5432,
            localHost: $connectionConfig['local_host'] ?? '127.0.0.1',
            localPort: $connectionConfig['local_port'] ?? 15432,
            sshOptions: $connectionConfig['ssh_options'] ?? [],
        );

        return new self($config);
    }

    /**
     * Create manager from configuration
     *
     * @param TunnelConfig $config
     * @return self
     */
    public static function fromConfig(TunnelConfig $config): self
    {
        return new self($config);
    }

    /**
     * Specify database connection to register
     *
     * @param string $connectionName Connection name (e.g., 'pgsql_remote')
     * @param array $config Database connection configuration
     * @return $this
     */
    public function withDatabaseConnection(string $connectionName, array $config): self
    {
        $this->connectionName = $connectionName;
        $this->databaseConfig = array_merge($config, [
            'host' => $this->config->localHost,
            'port' => $this->config->localPort,
        ]);

        return $this;
    }

    /**
     * Start tunnel
     *
     * @return TunnelConnection
     * @throws TunnelConnectionException
     */
    public function start(): TunnelConnection
    {
        if ($this->connection && $this->connection->isRunning()) {
            Log::debug('SSH tunnel already active, reusing connection');
            return $this->connection;
        }

        // Check for existing tunnel via PID file
        $existingPid = $this->findExistingTunnelProcess();

        if ($existingPid) {
            Log::info("Found existing SSH tunnel (PID: {$existingPid}), reusing it");

            // Create a proxy connection for the existing tunnel
            $this->connection = new TunnelConnection($this->config);
            $this->connection->setExistingPid($existingPid);

            // Register DB connection for the existing tunnel
            if ($this->connectionName && $this->databaseConfig) {
                $this->registerDatabaseConnection();
            }

            return $this->connection;
        }

        // Create new tunnel
        $this->connection = new TunnelConnection($this->config);

        // Set callback to remove PID file on stop
        $this->connection->setOnStopCallback(function() {
            $this->removePidFile();
        });

        $this->connection->start();

        // Save PID to file
        $pid = $this->connection->getPid();
        if ($pid) {
            $this->savePidFile($pid);
        }

        // Register database connection if specified
        if ($this->connectionName && $this->databaseConfig) {
            $this->registerDatabaseConnection();
        }

        return $this->connection;
    }

    /**
     * Get path to directory for storing PID files
     *
     * @return string
     */
    protected function getPidDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/laravel-autossh-tunnel';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Get PID file path for this tunnel
     *
     * @return string
     */
    protected function getPidFilePath(): string
    {
        return $this->getPidDirectory() . '/' . $this->config->getIdentifier() . '.pid';
    }

    /**
     * Save PID to file
     *
     * @param int $pid
     * @return void
     */
    protected function savePidFile(int $pid): void
    {
        $pidFile = $this->getPidFilePath();
        file_put_contents($pidFile, $pid);

        Log::debug("Saved PID file", [
            'path' => $pidFile,
            'pid' => $pid,
        ]);
    }

    /**
     * Remove PID file
     *
     * @return void
     */
    protected function removePidFile(): void
    {
        $pidFile = $this->getPidFilePath();

        if (file_exists($pidFile)) {
            unlink($pidFile);
            Log::debug("Removed PID file", ['path' => $pidFile]);
        }
    }

    /**
     * Read PID from file
     *
     * @return int|null
     */
    protected function readPidFile(): ?int
    {
        $pidFile = $this->getPidFilePath();

        if (!file_exists($pidFile)) {
            return null;
        }

        $pid = (int) trim(file_get_contents($pidFile));

        return $pid > 0 ? $pid : null;
    }

    /**
     * Check if port is in use
     *
     * @param string $host Host to check
     * @param int $port Port to check
     * @return bool
     */
    protected function isPortInUse(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);
            return true;
        }

        return false;
    }

    /**
     * Find existing SSH tunnel
     * Checks: 1) PID file existence, 2) process existence, 3) port availability
     *
     * @return int|null Process PID or null if not found
     */
    protected function findExistingTunnelProcess(): ?int
    {
        // Step 1: Read PID from file
        $pid = $this->readPidFile();

        if (!$pid) {
            Log::debug('No PID file found for this tunnel');
            return null;
        }

        Log::debug("Found PID file with PID: {$pid}");

        // Step 2: Verify process is running
        if (!$this->isProcessRunning($pid)) {
            Log::warning("Process {$pid} from PID file is not running, cleaning up");
            $this->removePidFile();
            return null;
        }

        // Step 3: Verify it's an SSH process
        $processName = trim(shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));

        if (strpos($processName, 'ssh') === false) {
            Log::warning("Process {$pid} is not an SSH process ('{$processName}'), cleaning up");
            $this->removePidFile();
            return null;
        }

        // Step 4: Verify port is accessible
        if (!$this->isPortInUse($this->config->localHost, $this->config->localPort)) {
            Log::warning("SSH process {$pid} exists but port {$this->config->localPort} is not accessible, cleaning up");
            $this->removePidFile();
            return null;
        }

        Log::info("Valid existing SSH tunnel found", [
            'pid' => $pid,
            'port' => $this->config->localPort,
            'process' => $processName,
        ]);

        return $pid;
    }

    /**
     * Check if process is running
     *
     * @param int $pid
     * @return bool
     */
    protected function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_getpgid')) {
            return posix_getpgid($pid) !== false;
        }

        // Fallback for systems without posix
        return file_exists("/proc/{$pid}");
    }

    /**
     * Execute callback with automatic tunnel management
     *
     * @param callable $callback Function to execute
     * @return mixed Callback execution result
     * @throws TunnelConnectionException
     */
    public function execute(callable $callback): mixed
    {
        $tunnel = $this->start();

        try {
            return $callback($tunnel);
        } finally {
            $tunnel->stop();
        }
    }

    /**
     * Register database connection in Laravel Config
     *
     * @return void
     */
    protected function registerDatabaseConnection(): void
    {
        if (!$this->connectionName || !$this->databaseConfig) {
            return;
        }

        Config::set("database.connections.{$this->connectionName}", $this->databaseConfig);

        Log::debug('Registered database connection', [
            'connection' => $this->connectionName,
            'host' => $this->databaseConfig['host'] ?? null,
            'port' => $this->databaseConfig['port'] ?? null,
        ]);
    }

    /**
     * Get tunnel configuration
     *
     * @return TunnelConfig
     */
    public function getConfig(): TunnelConfig
    {
        return $this->config;
    }

    /**
     * Get active connection
     *
     * @return TunnelConnection|null
     */
    public function getConnection(): ?TunnelConnection
    {
        return $this->connection;
    }

    /**
     * Find existing tunnel by port using lsof
     *
     * Unlike findExistingTunnelProcess(), this method doesn't rely on PID file
     * but finds process directly by occupied port using lsof/netstat
     *
     * @return int|null PID of existing tunnel or null
     */
    public function findExistingByPort(): ?int
    {
        $processManager = $this->getProcessManager();

        // Find process occupying the tunnel's local port
        $pid = $processManager->findProcessByPort($this->config->localPort);

        if (!$pid) {
            Log::debug("No process found on port {$this->config->localPort}");
            return null;
        }

        // Verify it's SSH process
        if (!$processManager->isSshProcess($pid)) {
            $info = $processManager->getProcessInfo($pid);
            $processName = $info['name'] ?? 'unknown';
            Log::warning("Process {$pid} on port {$this->config->localPort} is not SSH (it's {$processName})");
            return null;
        }

        Log::info("Found existing SSH tunnel by port", [
            'pid' => $pid,
            'port' => $this->config->localPort,
        ]);

        return $pid;
    }

    /**
     * Smart tunnel reuse or creation
     *
     * Strategy:
     * 1. Check if tunnel already active in this instance
     * 2. Try to find existing tunnel by PID file
     * 3. Try to find existing tunnel by port (lsof)
     * 4. Create new tunnel if not found
     *
     * @return TunnelConnection
     * @throws TunnelConnectionException
     */
    public function reuseOrCreate(): TunnelConnection
    {
        // Step 1: If we already have active connection - reuse it
        if ($this->connection && $this->connection->isRunning()) {
            Log::debug('SSH tunnel already active in current instance, reusing');
            return $this->connection;
        }

        // Step 2: Try to find by PID file
        $existingPid = $this->findExistingTunnelProcess();

        // Step 3: If PID file not found, try to find by port
        if (!$existingPid) {
            $existingPid = $this->findExistingByPort();

            // If found by port - save PID to file for future use
            if ($existingPid) {
                $this->savePidFile($existingPid);
            }
        }

        // If found existing tunnel - reuse it
        if ($existingPid) {
            Log::info("Reusing existing SSH tunnel (PID: {$existingPid})");

            $this->connection = new TunnelConnection($this->config);
            $this->connection->setExistingPid($existingPid);

            // Register database connection if specified
            if ($this->connectionName && $this->databaseConfig) {
                $this->registerDatabaseConnection();
            }

            return $this->connection;
        }

        // Step 4: No existing tunnel found - create new one
        Log::info("No existing tunnel found, creating new one");
        return $this->start();
    }

    /**
     * Ensure tunnel is active, reconnect if needed
     *
     * @param int $maxAttempts Maximum number of reconnection attempts
     * @return bool
     */
    public function ensureConnected(int $maxAttempts = 3): bool
    {
        if (!$this->connection) {
            Log::warning('Tunnel connection not initialized');
            return false;
        }

        return $this->connection->ensureConnected($maxAttempts);
    }
}
