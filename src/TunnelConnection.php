<?php

namespace ArtemYurov\Autossh;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Represents an active SSH tunnel connection
 */
class TunnelConnection
{
    protected ?Process $process = null;
    protected bool $isRunning = false;
    protected ?int $existingPid = null; // PID существующего туннеля (не созданного нами)
    protected $onStopCallback = null; // Callback вызываемый при остановке туннеля

    public function __construct(
        protected readonly TunnelConfig $config
    ) {
    }

    /**
     * Установить callback для вызова при остановке туннеля
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

        // Check local port availability
        if ($this->isPortInUse($this->config->localPort)) {
            throw new TunnelConnectionException(
                "Local port {$this->config->localPort} is already in use. " .
                "Tunnel may already be active or port is used by another process."
            );
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

        // Give tunnel time to establish
        sleep(2);

        // Verify tunnel started
        if (!$this->process->isRunning()) {
            $error = $this->process->getErrorOutput();

            // Special handling for "Too many authentication failures"
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

        // Verify port is accessible
        if (!$this->verifyConnection()) {
            $this->stop();
            throw new TunnelConnectionException(
                "SSH tunnel started but port {$this->config->localPort} is not accessible"
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
        // Если это существующий туннель (не созданный нами) - не останавливаем его
        if ($this->existingPid) {
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

            // Вызываем callback для очистки (например, удаления PID файла)
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
        // Если это существующий туннель - проверяем только порт
        if ($this->existingPid) {
            return $this->isPortInUse($this->config->localPort);
        }

        if (!$this->process || !$this->process->isRunning()) {
            return false;
        }

        return $this->isPortInUse($this->config->localPort);
    }

    /**
     * Восстановить туннель если он отвалился
     *
     * @param int $maxAttempts Максимальное количество попыток
     * @return bool Успешно ли восстановлен
     */
    public function ensureConnected(int $maxAttempts = 3): bool
    {
        if ($this->verifyConnection()) {
            return true;
        }

        Log::warning('SSH tunnel connection lost, attempting to reconnect...', [
            'config' => (string) $this->config,
        ]);

        // Если это был существующий туннель - не пытаемся его восстановить
        if ($this->existingPid) {
            Log::error('External SSH tunnel is down, cannot reconnect', [
                'pid' => $this->existingPid,
            ]);
            return false;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Log::info("Reconnection attempt {$attempt}/{$maxAttempts}");

                // Останавливаем старое соединение если оно есть
                if ($this->process && $this->process->isRunning()) {
                    $this->process->stop();
                }

                // Ждём освобождения порта
                $waitAttempts = 0;
                while ($this->isPortInUse($this->config->localPort) && $waitAttempts < 10) {
                    sleep(1);
                    $waitAttempts++;
                }

                // Создаём новое соединение
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
     * Установить PID существующего туннеля
     *
     * @param int $pid
     * @return $this
     */
    public function setExistingPid(int $pid): self
    {
        $this->existingPid = $pid;
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
     * Automatically close tunnel on object destruction
     */
    public function __destruct()
    {
        $this->stop();
    }
}
