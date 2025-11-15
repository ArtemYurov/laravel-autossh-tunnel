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

    public function __construct(
        protected readonly TunnelConfig $config
    ) {
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
        if (!$this->process || !$this->process->isRunning()) {
            return false;
        }

        return $this->isPortInUse($this->config->localPort);
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
     * Get tunnel process PID
     *
     * @return int|null
     */
    public function getPid(): ?int
    {
        return $this->process?->getPid();
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
