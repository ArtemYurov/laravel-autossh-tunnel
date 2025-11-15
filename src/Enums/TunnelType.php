<?php

namespace ArtemYurov\Autossh\Enums;

/**
 * SSH Tunnel Types
 */
enum TunnelType: string
{
    /**
     * Forward Tunnel (-L)
     *
     * Access remote services from local machine
     * Example: Connect to remote database
     *
     * ssh -L local_port:remote_host:remote_port user@server
     */
    case Forward = 'forward';

    /**
     * Reverse Tunnel (-R)
     *
     * Expose local services to remote server
     * Example: Webhook testing, local development
     *
     * ssh -R remote_port:localhost:local_port user@server
     */
    case Reverse = 'reverse';

    /**
     * Get tunnel type from string
     */
    public static function fromString(string $type): self
    {
        return match(strtolower($type)) {
            'forward', 'f', '-l' => self::Forward,
            'reverse', 'r', '-r' => self::Reverse,
            default => throw new \InvalidArgumentException("Invalid tunnel type: {$type}. Use 'forward' or 'reverse'"),
        };
    }

    /**
     * Check if this is a forward tunnel
     */
    public function isForward(): bool
    {
        return $this === self::Forward;
    }

    /**
     * Check if this is a reverse tunnel
     */
    public function isReverse(): bool
    {
        return $this === self::Reverse;
    }
}
