<?php

namespace ArtemYurov\Autossh\DTO;

use ArtemYurov\Autossh\Enums\TunnelType;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;
use Symfony\Component\Process\Process;

/**
 * SSH tunnel configuration DTO
 */
readonly class TunnelConfig
{
    /**
     * @param TunnelType $type Tunnel type (forward or reverse)
     * @param string $user SSH user
     * @param string $host SSH host
     * @param int $port SSH port
     * @param string|null $identityFile Path to SSH key
     * @param string $remoteHost Remote host on SSH server
     * @param int $remotePort Remote port on SSH server
     * @param string $localHost Local host for database connection (127.0.0.1 or localhost)
     * @param int $localPort Local port for tunnel
     * @param array $sshOptions SSH connection options (key-value pairs, e.g. ['StrictHostKeyChecking' => 'no'])
     */
    public function __construct(
        public TunnelType $type = TunnelType::Forward,
        public string $user = '',
        public string $host = '',
        public int $port = 22,
        public ?string $identityFile = null,
        public string $remoteHost = 'localhost',
        public int $remotePort = 5432,
        public string $localHost = '127.0.0.1',
        public int $localPort = 15432,
        public array $sshOptions = [],
    ) {
        $this->validate();
    }

    /**
     * Get default SSH options
     */
    public static function getDefaultSshOptions(): array
    {
        return [
            'StrictHostKeyChecking' => false,
            'ServerAliveInterval' => 60,
            'ServerAliveCountMax' => 3,
            'ExitOnForwardFailure' => true,
            'TCPKeepAlive' => true,
            'ConnectTimeout' => 10,
        ];
    }

    /**
     * Normalize option value for SSH command
     * Converts boolean to 'yes'/'no', everything else to string
     */
    protected function normalizeOptionValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return (string) $value;
    }

    /**
     * Build SSH options by merging defaults with user options
     */
    protected function buildSshOptions(): array
    {
        return array_merge(self::getDefaultSshOptions(), $this->sshOptions);
    }

    /**
     * Validate configuration
     *
     * @throws TunnelConfigException
     */
    protected function validate(): void
    {
        if (empty($this->user)) {
            throw new TunnelConfigException('SSH user cannot be empty');
        }

        if (empty($this->host)) {
            throw new TunnelConfigException('SSH host cannot be empty');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new TunnelConfigException("Invalid SSH port: {$this->port}");
        }

        if ($this->localPort < 1 || $this->localPort > 65535) {
            throw new TunnelConfigException("Invalid local port: {$this->localPort}");
        }

        if ($this->remotePort < 1 || $this->remotePort > 65535) {
            throw new TunnelConfigException("Invalid remote port: {$this->remotePort}");
        }

        if ($this->identityFile && !file_exists($this->identityFile)) {
            throw new TunnelConfigException(
                "SSH key not found: {$this->identityFile}"
            );
        }
    }

    /**
     * Get SSH command for creating tunnel
     *
     * @param bool $useAutossh Use autossh if available
     * @return string
     */
    public function getSshCommand(bool $useAutossh = false): string
    {
        if ($useAutossh) {
            return $this->getAutosshCommand();
        }

        $parts = ['ssh', '-N'];
        $parts = array_merge($parts, $this->buildCommonSshParts());

        return implode(' ', $parts);
    }

    /**
     * Build common SSH command parts (options, key, forwarding, connection)
     */
    protected function buildCommonSshParts(): array
    {
        $parts = [];

        // SSH options
        foreach ($this->buildSshOptions() as $key => $value) {
            $parts[] = sprintf('-o %s=%s', $key, $this->normalizeOptionValue($value));
        }

        // Add key if specified
        if ($this->identityFile) {
            $parts[] = '-i ' . escapeshellarg($this->identityFile);
        }

        // Port forwarding (Forward or Reverse)
        switch ($this->type) {
            case TunnelType::Forward:
                // Forward tunnel: ssh -L [bind_address:]local_port:remote_host:remote_port
                // Bind address included to force IPv4/IPv6 preference
                $parts[] = sprintf(
                    '-L %s:%d:%s:%d',
                    $this->localHost,
                    $this->localPort,
                    $this->remoteHost,
                    $this->remotePort
                );
                break;

            case TunnelType::Reverse:
                // Reverse tunnel: ssh -R [bind_address:]remote_port:remote_host:local_port
                $parts[] = sprintf(
                    '-R %s:%d:%s:%d',
                    $this->localHost,
                    $this->remotePort,
                    $this->remoteHost,
                    $this->localPort
                );
                break;
        }

        // SSH connection
        $parts[] = sprintf(
            '-p %d %s@%s',
            $this->port,
            escapeshellarg($this->user),
            escapeshellarg($this->host)
        );

        return $parts;
    }

    /**
     * Get autossh command for creating tunnel
     *
     * @return string
     */
    protected function getAutosshCommand(): string
    {
        // Determine autossh path
        $autosshPath = $this->findAutosshPath();

        $parts = [
            $autosshPath,
            '-M 0', // Disable monitoring port (use ServerAlive instead)
            '-N',   // Don't execute commands
            // DO NOT use -f (background mode) as we manage the process via Symfony Process
        ];

        $parts = array_merge($parts, $this->buildCommonSshParts());

        return implode(' ', $parts);
    }

    /**
     * Find autossh path using command -v
     *
     * @return string
     */
    protected function findAutosshPath(): string
    {
        // Use command -v to find autossh in PATH (POSIX-compatible)
        $process = Process::fromShellCommandline('command -v autossh');
        $process->run();

        if ($process->isSuccessful() && !empty(trim($process->getOutput()))) {
            return trim($process->getOutput());
        }

        // Fallback to just 'autossh' and let the shell find it
        return 'autossh';
    }

    /**
     * Get unique identifier for this tunnel configuration
     * Used for PID file naming
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return md5(sprintf(
            '%s_%s_%d_%s_%d_%s_%d',
            $this->type->value,
            $this->user,
            $this->port,
            $this->host,
            $this->localPort,
            $this->remoteHost,
            $this->remotePort
        ));
    }

    /**
     * Get string representation of configuration
     *
     * @return string
     */
    public function __toString(): string
    {
        return match($this->type) {
            TunnelType::Forward => sprintf(
                '[forward] %s@%s:%d -> localhost:%d -> %s:%d',
                $this->user,
                $this->host,
                $this->port,
                $this->localPort,
                $this->remoteHost,
                $this->remotePort
            ),
            TunnelType::Reverse => sprintf(
                '[reverse] %s@%s:%d <- localhost:%d <- remote:%d',
                $this->user,
                $this->host,
                $this->port,
                $this->localPort,
                $this->remotePort
            ),
        };
    }
}
