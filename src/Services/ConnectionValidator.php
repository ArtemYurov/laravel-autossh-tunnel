<?php

namespace ArtemYurov\Autossh\Services;

use Illuminate\Support\Facades\DB;

/**
 * Service for validating SSH tunnel and database connections
 *
 * Checks not only port availability but also real database workability
 */
class ConnectionValidator
{
    /**
     * @var ProcessManager
     */
    protected ProcessManager $processManager;

    public function __construct(?ProcessManager $processManager = null)
    {
        $this->processManager = $processManager ?? new ProcessManager();
    }

    /**
     * Check port accessibility
     *
     * @param int $port Port number
     * @param string $host Host (default 127.0.0.1)
     * @param int $timeout Timeout in seconds
     * @return bool true if port is accessible
     */
    public function isPortAccessible(int $port, string $host = '127.0.0.1', int $timeout = 1): bool
    {
        return $this->processManager->isPortInUse($port, $host);
    }

    /**
     * Check database accessibility through database connection
     *
     * Attempts to execute simple SELECT 1 query to verify connection
     *
     * @param string $connectionName Database connection name
     * @param int $timeout Timeout in seconds
     * @return bool true if database is accessible
     */
    public function isDatabaseAccessible(string $connectionName, int $timeout = 5): bool
    {
        try {
            // Set timeout for connection
            $originalTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', (string)$timeout);

            // Try to execute simple query
            $result = DB::connection($connectionName)->select('SELECT 1 as test');

            // Restore timeout
            ini_set('default_socket_timeout', $originalTimeout);

            return !empty($result);

        } catch (\Exception $e) {
            // Restore timeout in case of error
            if (isset($originalTimeout)) {
                ini_set('default_socket_timeout', $originalTimeout);
            }

            return false;
        }
    }

    /**
     * Full SSH tunnel validation
     *
     * Checks:
     * 1. Tunnel process existence
     * 2. Process is actually SSH
     * 3. Port availability
     * 4. Database accessibility (if connectionName specified)
     *
     * @param int $pid Tunnel process PID
     * @param int $localPort Tunnel local port
     * @param string|null $connectionName Database connection to check (optional)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateTunnel(int $pid, int $localPort, ?string $connectionName = null): array
    {
        $errors = [];

        // 1. Check process existence
        if (!$this->processManager->isProcessRunning($pid)) {
            $errors[] = "Tunnel process (PID: {$pid}) is not running";
            return ['valid' => false, 'errors' => $errors];
        }

        // 2. Check it's SSH process
        if (!$this->processManager->isSshProcess($pid)) {
            $info = $this->processManager->getProcessInfo($pid);
            $processName = $info['name'] ?? 'unknown';
            $errors[] = "Process (PID: {$pid}) is not SSH tunnel (it's {$processName})";
            return ['valid' => false, 'errors' => $errors];
        }

        // 3. Check port availability
        if (!$this->isPortAccessible($localPort)) {
            $errors[] = "Port {$localPort} is not accessible";
        }

        // 4. Check database if connectionName specified
        if ($connectionName !== null) {
            if (!$this->isDatabaseAccessible($connectionName)) {
                $errors[] = "Database connection '{$connectionName}' not accessible through tunnel";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Wait for database availability with retries
     *
     * Attempts to connect to database several times with delay between attempts
     *
     * @param string $connectionName
     * @param int $maxAttempts Maximum number of attempts
     * @param int $delaySeconds Delay between attempts in seconds
     * @return bool true if database became available
     */
    public function waitForDatabase(string $connectionName, int $maxAttempts = 5, int $delaySeconds = 2): bool
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->isDatabaseAccessible($connectionName)) {
                return true;
            }

            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
            }
        }

        return false;
    }

    /**
     * Get detailed database connection error information
     *
     * @param string $connectionName
     * @return string Error message
     */
    public function getDatabaseConnectionError(string $connectionName): string
    {
        try {
            DB::connection($connectionName)->select('SELECT 1');
            return 'No error';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
