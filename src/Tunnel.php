<?php

namespace ArtemYurov\Autossh;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
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

    public function __construct(
        protected readonly TunnelConfig $config
    ) {
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
                "Tunnel connection '{$name}' not found in config/tunnel.php. " .
                "Available connections: " . implode(', ', array_keys(config('tunnel.connections', [])))
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

        // Проверяем наличие существующего туннеля через PID файл
        $existingPid = $this->findExistingTunnelProcess();

        if ($existingPid) {
            Log::info("Found existing SSH tunnel (PID: {$existingPid}), reusing it");

            // Создаём фиктивное подключение для существующего туннеля
            $this->connection = new TunnelConnection($this->config);
            $this->connection->setExistingPid($existingPid);

            // Регистрируем DB connection для существующего туннеля
            if ($this->connectionName && $this->databaseConfig) {
                $this->registerDatabaseConnection();
            }

            return $this->connection;
        }

        // Создаём новый туннель
        $this->connection = new TunnelConnection($this->config);

        // Устанавливаем callback для удаления PID файла при остановке
        $this->connection->setOnStopCallback(function() {
            $this->removePidFile();
        });

        $this->connection->start();

        // Сохраняем PID в файл
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
     * Получить путь к директории для хранения PID файлов
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
     * Получить путь к PID файлу для данного туннеля
     *
     * @return string
     */
    protected function getPidFilePath(): string
    {
        return $this->getPidDirectory() . '/' . $this->config->getIdentifier() . '.pid';
    }

    /**
     * Сохранить PID в файл
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
     * Удалить PID файл
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
     * Прочитать PID из файла
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
     * Найти существующий SSH туннель
     * Проверяет: 1) наличие PID файла, 2) существование процесса, 3) доступность порта
     *
     * @return int|null PID процесса или null если не найден
     */
    protected function findExistingTunnelProcess(): ?int
    {
        // Шаг 1: Читаем PID из файла
        $pid = $this->readPidFile();

        if (!$pid) {
            Log::debug('No PID file found for this tunnel');
            return null;
        }

        Log::debug("Found PID file with PID: {$pid}");

        // Шаг 2: Проверяем что процесс запущен
        if (!$this->isProcessRunning($pid)) {
            Log::warning("Process {$pid} from PID file is not running, cleaning up");
            $this->removePidFile();
            return null;
        }

        // Шаг 3: Проверяем что это SSH процесс
        $processName = trim(shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));

        if (strpos($processName, 'ssh') === false) {
            Log::warning("Process {$pid} is not an SSH process ('{$processName}'), cleaning up");
            $this->removePidFile();
            return null;
        }

        // Шаг 4: Проверяем что порт доступен
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
     * Проверка запущен ли процесс
     *
     * @param int $pid
     * @return bool
     */
    protected function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_getpgid')) {
            return posix_getpgid($pid) !== false;
        }

        // Fallback для систем без posix
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
     * Убедиться что туннель активен, переподключиться если нужно
     *
     * @param int $maxAttempts Максимальное количество попыток переподключения
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
