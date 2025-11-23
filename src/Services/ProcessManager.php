<?php

namespace ArtemYurov\Autossh\Services;

/**
 * Service for managing operating system processes
 *
 * Provides methods for:
 * - Finding processes by occupied port
 * - Checking process existence
 * - Getting process information
 * - Managing processes (kill)
 */
class ProcessManager
{
    /**
     * Find PID of process occupying specified port
     *
     * Uses lsof on macOS/Linux, netstat as fallback
     *
     * @param int $port Port number
     * @return int|null Process PID or null if not found
     */
    public function findProcessByPort(int $port): ?int
    {
        // Try lsof (most reliable method)
        $pid = $this->findByPortUsingLsof($port);
        if ($pid) {
            return $pid;
        }

        // Fallback to netstat if lsof is unavailable
        return $this->findByPortUsingNetstat($port);
    }

    /**
     * Find process using lsof (macOS/Linux)
     *
     * @param int $port
     * @return int|null
     */
    protected function findByPortUsingLsof(int $port): ?int
    {
        // Check lsof availability
        if (!$this->commandExists('lsof')) {
            return null;
        }

        // lsof -ti:PORT returns PIDs of processes listening on port
        $command = sprintf('lsof -ti:%d 2>/dev/null | head -1', $port);
        $output = trim(shell_exec($command) ?? '');

        $pid = (int) $output;
        return $pid > 0 ? $pid : null;
    }

    /**
     * Find process using netstat (fallback)
     *
     * @param int $port
     * @return int|null
     */
    protected function findByPortUsingNetstat(int $port): ?int
    {
        if (!$this->commandExists('netstat')) {
            return null;
        }

        // Different commands for different OS
        $commands = [
            // Linux
            sprintf('netstat -tlnp 2>/dev/null | grep ":%d " | awk \'{print $7}\' | cut -d/ -f1 | head -1', $port),
            // macOS
            sprintf('netstat -anv | grep "LISTEN" | grep ".%d " | awk \'{print $9}\' | head -1', $port),
        ];

        foreach ($commands as $command) {
            $output = trim(shell_exec($command) ?? '');
            $pid = (int) $output;
            if ($pid > 0) {
                return $pid;
            }
        }

        return null;
    }

    /**
     * Check if process with specified PID is running
     *
     * @param int $pid Process PID
     * @return bool true if process is running
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Use posix_getpgid if available (most reliable method)
        if (function_exists('posix_getpgid')) {
            return posix_getpgid($pid) !== false;
        }

        // Fallback to /proc/$pid (Linux)
        if (file_exists("/proc/{$pid}")) {
            return true;
        }

        // Fallback to ps command
        $output = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
        return !empty(trim($output ?? ''));
    }

    /**
     * Get process information
     *
     * @param int $pid Process PID
     * @return array ['pid' => int, 'name' => string, 'command' => string] or []
     */
    public function getProcessInfo(int $pid): array
    {
        if (!$this->isProcessRunning($pid)) {
            return [];
        }

        // Get process name
        $name = trim(shell_exec("ps -p {$pid} -o comm= 2>/dev/null") ?? '');

        // Get full command
        $command = trim(shell_exec("ps -p {$pid} -o args= 2>/dev/null") ?? '');

        if (empty($name) && empty($command)) {
            return [];
        }

        return [
            'pid' => $pid,
            'name' => $name,
            'command' => $command,
        ];
    }

    /**
     * Check if process is SSH tunnel
     *
     * @param int $pid Process PID
     * @return bool true if process is ssh
     */
    public function isSshProcess(int $pid): bool
    {
        $info = $this->getProcessInfo($pid);

        if (empty($info)) {
            return false;
        }

        // Check process name
        $name = strtolower($info['name'] ?? '');
        if (str_contains($name, 'ssh')) {
            return true;
        }

        // Check command (might be /usr/bin/ssh)
        $command = strtolower($info['command'] ?? '');
        return str_contains($command, 'ssh');
    }

    /**
     * Stop process
     *
     * @param int $pid Process PID
     * @param int $signal Signal to send (default SIGTERM=15)
     * @return bool true if process successfully stopped
     */
    public function killProcess(int $pid, int $signal = 15): bool
    {
        if (!$this->isProcessRunning($pid)) {
            return true; // Already stopped
        }

        // Send signal
        $result = shell_exec("kill -{$signal} {$pid} 2>/dev/null");

        // Wait a bit
        usleep(100000); // 100ms

        // Check that process is stopped
        return !$this->isProcessRunning($pid);
    }

    /**
     * Check if command is available in system
     *
     * @param string $command Command name
     * @return bool true if command is available
     */
    protected function commandExists(string $command): bool
    {
        $output = shell_exec("which {$command} 2>/dev/null");
        return !empty(trim($output ?? ''));
    }

    /**
     * Check if specified port is in use
     *
     * @param int $port Port number
     * @param string $host Host (default 127.0.0.1)
     * @return bool true if port is in use
     */
    public function isPortInUse(int $port, string $host = '127.0.0.1'): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        return false;
    }
}
