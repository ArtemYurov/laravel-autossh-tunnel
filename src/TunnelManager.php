<?php

namespace ArtemYurov\Autossh;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use Illuminate\Support\Facades\Storage;

/**
 * Manages persistent tunnel connections
 */
class TunnelManager
{
    protected string $pidDirectory;

    public function __construct()
    {
        $this->pidDirectory = storage_path('app/tunnels');

        if (!is_dir($this->pidDirectory)) {
            mkdir($this->pidDirectory, 0755, true);
        }
    }

    /**
     * Get PID file path for connection
     */
    protected function getPidFile(string $connectionName): string
    {
        return $this->pidDirectory . '/' . $connectionName . '.pid';
    }

    /**
     * Save tunnel connection info
     */
    public function saveTunnel(string $connectionName, TunnelConnection $connection): void
    {
        $pidFile = $this->getPidFile($connectionName);

        $data = [
            'pid' => $connection->getPid(),
            'connection_name' => $connectionName,
            'config' => [
                'local_port' => $connection->getConfig()->localPort,
                'remote_host' => $connection->getConfig()->remoteHost,
                'remote_port' => $connection->getConfig()->remotePort,
                'user' => $connection->getConfig()->user,
                'host' => $connection->getConfig()->host,
            ],
            'started_at' => time(),
        ];

        file_put_contents($pidFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get tunnel info
     */
    public function getTunnelInfo(string $connectionName): ?array
    {
        $pidFile = $this->getPidFile($connectionName);

        if (!file_exists($pidFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($pidFile), true);

        // Check if process is still running
        if (!$this->isProcessRunning($data['pid'])) {
            $this->removeTunnel($connectionName);
            return null;
        }

        return $data;
    }

    /**
     * Remove tunnel info
     */
    public function removeTunnel(string $connectionName): void
    {
        $pidFile = $this->getPidFile($connectionName);

        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }

    /**
     * Check if process is running
     */
    protected function isProcessRunning(int $pid): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
            return count($output) > 1;
        } else {
            // Unix/Linux/macOS
            return posix_kill($pid, 0);
        }
    }

    /**
     * Stop tunnel by connection name
     */
    public function stopTunnel(string $connectionName): bool
    {
        $info = $this->getTunnelInfo($connectionName);

        if (!$info) {
            return false;
        }

        $pid = $info['pid'];

        // Try SIGTERM first
        if (posix_kill($pid, SIGTERM)) {
            sleep(1);

            // Check if still running, then SIGKILL
            if ($this->isProcessRunning($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        $this->removeTunnel($connectionName);

        return true;
    }

    /**
     * Get all running tunnels
     */
    public function getAllTunnels(): array
    {
        $tunnels = [];
        $files = glob($this->pidDirectory . '/*.pid');

        foreach ($files as $file) {
            $connectionName = basename($file, '.pid');
            $info = $this->getTunnelInfo($connectionName);

            if ($info) {
                $tunnels[$connectionName] = $info;
            }
        }

        return $tunnels;
    }

    /**
     * Calculate uptime in seconds
     */
    public function getUptime(array $tunnelInfo): int
    {
        return time() - $tunnelInfo['started_at'];
    }

    /**
     * Format uptime as human readable string
     */
    public function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }

        return implode(' ', $parts);
    }
}
