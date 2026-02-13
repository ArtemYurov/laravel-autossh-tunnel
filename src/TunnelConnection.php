<?php

namespace ArtemYurov\Autossh;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
use ArtemYurov\Autossh\Services\ConnectionValidator;
use ArtemYurov\Autossh\Services\RetryManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Represents an active SSH tunnel connection
 */
class TunnelConnection
{
    protected ?Process $process = null;
    protected bool $isRunning = false;
    protected ?int $existingPid = null; // PID of existing tunnel (not created by us)
    protected bool $isExternalTunnel = false; // Tunnel was not created by us
    protected $onStopCallback = null; // Callback called when tunnel stops
    protected bool $keepAlive = false; // Keep tunnel running after script ends
    protected bool $signalHandlersSetup = false; // Flag indicating signal handlers are set up
    protected ?ConnectionValidator $validator = null;
    protected ?RetryManager $retryManager = null;

    public function __construct(
        protected readonly TunnelConfig $config
    ) {
    }

    /**
     * Set callback to be called when tunnel stops
     *
     * @param callable $callback
     * @return self
     */
    public function setOnStopCallback(callable $callback): self
    {
        $this->onStopCallback = $callback;
        return $this;
    }

    /**
     * Start SSH tunnel
     *
     * @return $this
     * @throws TunnelConnectionException
     */
    public function start(): self
    {
        if ($this->isRunning) {
            Log::warning('SSH tunnel already running', ['config' => (string) $this->config]);
            return $this;
        }

        // Check local port availability — reuse existing tunnel if port is already in use
        if ($this->isPortInUse($this->config->localPort)) {
            Log::info('Port already in use, reusing existing tunnel', [
                'port' => $this->config->localPort,
            ]);
            $this->isRunning = true;
            $this->isExternalTunnel = true;
            $this->existingPid = $this->findPidByPort($this->config->localPort);
            return $this;
        }

        $sshCommand = $this->config->getSshCommand($this->isAutosshAvailable());

        Log::info('Starting SSH tunnel', [
            'config' => (string) $this->config,
            'command' => $sshCommand,
            'using_autossh' => $this->isAutosshAvailable(),
        ]);

        // In dev mode output command for debugging
        if (config('app.debug')) {
            Log::debug("SSH tunnel command: {$sshCommand}");
        }

        // Create and start SSH tunnel process
        $this->process = Process::fromShellCommandline($sshCommand);
        $this->process->setTimeout(null); // Tunnel runs indefinitely
        $this->process->start();

        // Wait for tunnel to establish connection (polling with timeout).
        // Long timeout needed for SSH agents with interactive confirmation (Secretive, Touch ID).
        $maxWait = (int) config('tunnel.validation.port_max_attempts', 10);
        $connected = false;

        for ($i = 0; $i < $maxWait; $i++) {
            sleep(1);

            // Check that process is still alive
            if (!$this->process->isRunning()) {
                $error = $this->process->getErrorOutput();

                if (str_contains($error, 'Too many authentication failures')) {
                    throw new TunnelConnectionException(
                        "SSH tunnel: Too many authentication failures.\n" .
                        "You have many keys in SSH agent and server is rejecting connection.\n\n" .
                        "Solutions:\n" .
                        "1. Add to ~/.ssh/config:\n" .
                        "   Host {$this->config->host}\n" .
                        "     IdentityFile ~/.ssh/your_key\n" .
                        "     IdentitiesOnly yes\n\n" .
                        "2. Or specify PGSQL_TUNNEL_KEY=/path/to/key in .env\n\n" .
                        "Original error:\n{$error}"
                    );
                }

                throw new TunnelConnectionException(
                    "Failed to start SSH tunnel: " . ($error ?: 'Process terminated')
                );
            }

            // Check that port is accessible
            if ($this->isPortInUse($this->config->localPort)) {
                $connected = true;
                break;
            }
        }

        if (!$connected) {
            $this->stop();
            throw new TunnelConnectionException(
                "SSH tunnel started but port {$this->config->localPort} is not accessible after {$maxWait}s"
            );
        }

        $this->isRunning = true;

        Log::info('SSH tunnel started successfully', [
            'pid' => $this->process->getPid(),
            'local_port' => $this->config->localPort,
        ]);

        return $this;
    }

    /**
     * Stop SSH tunnel
     *
     * @return void
     */
    public function stop(): void
    {
        // If this is an existing tunnel (not created by us) - don't stop it
        if ($this->isExternalTunnel) {
            Log::debug("Not stopping existing SSH tunnel (PID: {$this->existingPid}), it was created by another process");
            return;
        }

        if (!$this->isRunning || !$this->process) {
            return;
        }

        Log::info('Stopping SSH tunnel', [
            'pid' => $this->process->getPid(),
        ]);

        try {
            // Stop process
            $this->process->stop(3, SIGTERM);

            // If process still running, kill it
            if ($this->process->isRunning()) {
                $this->process->signal(SIGKILL);
            }

            $this->isRunning = false;

            // Call cleanup callback (e.g., to remove PID file)
            if ($this->onStopCallback) {
                call_user_func($this->onStopCallback);
            }

            Log::info('SSH tunnel stopped successfully');
        } catch (\Exception $e) {
            Log::error('Error stopping SSH tunnel', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify tunnel is working
     *
     * @return bool
     */
    public function verifyConnection(): bool
    {
        // If this is an existing tunnel - only check the port
        if ($this->isExternalTunnel) {
            return $this->isPortInUse($this->config->localPort);
        }

        if (!$this->process || !$this->process->isRunning()) {
            return false;
        }

        return $this->isPortInUse($this->config->localPort);
    }

    /**
     * Reconnect tunnel if connection was lost
     *
     * @param int $maxAttempts Maximum number of attempts
     * @return bool Whether reconnection was successful
     */
    public function ensureConnected(int $maxAttempts = 3): bool
    {
        if ($this->verifyConnection()) {
            return true;
        }

        Log::warning('SSH tunnel connection lost, attempting to reconnect...', [
            'config' => (string) $this->config,
        ]);

        // If this was an existing tunnel - don't attempt to reconnect it
        if ($this->isExternalTunnel) {
            Log::error('External SSH tunnel is down, cannot reconnect', [
                'pid' => $this->existingPid,
            ]);
            return false;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Log::info("Reconnection attempt {$attempt}/{$maxAttempts}");

                // Stop old connection if it exists
                if ($this->process && $this->process->isRunning()) {
                    $this->process->stop();
                }

                // Wait for port to become available
                $waitAttempts = 0;
                while ($this->isPortInUse($this->config->localPort) && $waitAttempts < 10) {
                    sleep(1);
                    $waitAttempts++;
                }

                // Create new connection
                $sshCommand = $this->config->getSshCommand($this->isAutosshAvailable());

                $this->process = Process::fromShellCommandline($sshCommand);
                $this->process->setTimeout(null);
                $this->process->start();

                sleep(2);

                if ($this->verifyConnection()) {
                    $this->isRunning = true;
                    Log::info('SSH tunnel reconnected successfully', [
                        'pid' => $this->process->getPid(),
                        'attempt' => $attempt,
                    ]);
                    return true;
                }
            } catch (\Exception $e) {
                Log::error("Reconnection attempt {$attempt} failed", [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $maxAttempts) {
                sleep(2);
            }
        }

        Log::error('Failed to reconnect SSH tunnel after all attempts');
        return false;
    }

    /**
     * Find PID of process listening on a given port
     *
     * @param int $port
     * @return int|null
     */
    protected function findPidByPort(int $port): ?int
    {
        $process = Process::fromShellCommandline("lsof -ti tcp:{$port} -sTCP:LISTEN 2>/dev/null | head -1");
        $process->run();

        if ($process->isSuccessful()) {
            $pid = trim($process->getOutput());
            return $pid !== '' ? (int) $pid : null;
        }

        return null;
    }

    /**
     * Check if port is in use
     *
     * @param int $port
     * @return bool
     */
    protected function isPortInUse(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        return false;
    }

    /**
     * Set PID of an existing tunnel
     *
     * @param int $pid
     * @return $this
     */
    public function setExistingPid(int $pid): self
    {
        $this->existingPid = $pid;
        $this->isExternalTunnel = true;
        $this->isRunning = true;

        Log::info("Using existing SSH tunnel", [
            'pid' => $pid,
            'local_port' => $this->config->localPort,
        ]);

        return $this;
    }

    /**
     * Get tunnel process PID
     *
     * @return int|null
     */
    public function getPid(): ?int
    {
        return $this->existingPid ?? $this->process?->getPid();
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
     * Check if tunnel is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->isRunning && $this->verifyConnection();
    }

    /**
     * Check autossh availability
     *
     * @return bool
     */
    protected function isAutosshAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            // Check via command -v (POSIX-compatible way)
            $process = Process::fromShellCommandline('command -v autossh');
            $process->run();

            if ($process->isSuccessful() && !empty(trim($process->getOutput()))) {
                $available = true;
                $path = trim($process->getOutput());
                Log::debug("autossh found: {$path}");
            } else {
                $available = false;
                Log::debug('autossh not found, will use regular ssh');
            }
        }

        return $available;
    }

    /**
     * Set keep-alive flag (don't close tunnel when script ends)
     *
     * @param bool $keepAlive
     * @return $this
     */
    public function withKeepAlive(bool $keepAlive = true): self
    {
        $this->keepAlive = $keepAlive;
        return $this;
    }

    /**
     * Set up system signal handlers (SIGINT, SIGTERM)
     *
     * Tunnel will be properly closed when signal is received
     *
     * @return $this
     */
    public function setupSignalHandlers(): self
    {
        if ($this->signalHandlersSetup) {
            return $this;
        }

        if (!function_exists('pcntl_signal')) {
            Log::warning('pcntl extension not available, signal handlers not set up');
            return $this;
        }

        $handler = function (int $signal) {
            Log::info("Received signal {$signal}, closing tunnel...");
            $this->stop();
            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);

        $this->signalHandlersSetup = true;

        Log::debug('Signal handlers set up for SSH tunnel');

        return $this;
    }

    /**
     * Validate database accessibility through database connection
     *
     * Executes SELECT 1 to verify real database connection
     *
     * @param string $connectionName Database connection name
     * @param int $timeout Timeout in seconds
     * @return bool true if database is accessible
     */
    public function validateDatabase(string $connectionName, int $timeout = 5): bool
    {
        $validator = $this->getValidator();
        return $validator->isDatabaseAccessible($connectionName, $timeout);
    }

    /**
     * Wait for database availability with retries
     *
     * @param string $connectionName
     * @param int $maxAttempts
     * @param int $delaySeconds
     * @return bool
     */
    public function waitForDatabase(string $connectionName, int $maxAttempts = 5, int $delaySeconds = 2): bool
    {
        $validator = $this->getValidator();
        return $validator->waitForDatabase($connectionName, $maxAttempts, $delaySeconds);
    }

    /**
     * Execute operation with automatic retry on connection errors
     *
     * @param callable $operation Operation to execute
     * @param int|null $maxAttempts Maximum attempts (null = from config)
     * @return mixed Operation result
     * @throws \Exception
     */
    public function executeWithRetry(callable $operation, ?int $maxAttempts = null)
    {
        $retryManager = $this->getRetryManager();

        if ($maxAttempts !== null) {
            $retryManager->setMaxAttempts($maxAttempts);
        }

        // Set up reconnect callback
        $retryManager->setReconnectCallback(function () {
            Log::info('Attempting to reconnect tunnel...');
            $this->ensureConnected();
        });

        return $retryManager->execute($operation, function (\Exception $e) {
            // Retry only for connection-related errors
            $message = strtolower($e->getMessage());
            return str_contains($message, 'connection') ||
                   str_contains($message, 'lost') ||
                   str_contains($message, 'gone away');
        });
    }

    /**
     * Full tunnel validation (process + port + database)
     *
     * @param string|null $connectionName Database connection to check (optional)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(?string $connectionName = null): array
    {
        $validator = $this->getValidator();

        $pid = $this->getPid();
        if (!$pid) {
            return [
                'valid' => false,
                'errors' => ['Tunnel PID not found'],
            ];
        }

        return $validator->validateTunnel($pid, $this->config->localPort, $connectionName);
    }

    /**
     * Get ConnectionValidator (lazy initialization)
     *
     * @return ConnectionValidator
     */
    protected function getValidator(): ConnectionValidator
    {
        if ($this->validator === null) {
            $this->validator = new ConnectionValidator();
        }

        return $this->validator;
    }

    /**
     * Get RetryManager (lazy initialization)
     *
     * @return RetryManager
     */
    protected function getRetryManager(): RetryManager
    {
        if ($this->retryManager === null) {
            $this->retryManager = new RetryManager();

            // Default settings
            $this->retryManager->setMaxAttempts(3);
            $this->retryManager->setDelay(2);
        }

        return $this->retryManager;
    }

    /**
     * Automatically close tunnel on object destruction
     */
    public function __destruct()
    {
        // Don't close tunnel if keep-alive is set
        if ($this->keepAlive) {
            Log::info('Tunnel keep-alive enabled, not closing on destruct', [
                'pid' => $this->getPid(),
            ]);
            return;
        }

        $this->stop();
    }
}
