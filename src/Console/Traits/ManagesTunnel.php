<?php

namespace ArtemYurov\Autossh\Console\Traits;

use ArtemYurov\Autossh\Tunnel;
use ArtemYurov\Autossh\TunnelConnection;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;

/**
 * Trait for managing SSH tunnels in Laravel commands
 *
 * Provides convenient methods for tunnel lifecycle management including:
 * - Tunnel initialization with smart reuse
 * - Signal handlers for graceful shutdown
 * - Database validation
 * - Retry logic for operations
 * - Keep-alive options
 *
 * Example usage:
 *
 * class YourCommand extends Command
 * {
 *     use ManagesTunnel;
 *
 *     public function handle()
 *     {
 *         $this->setupTunnel('remote_db', [
 *             'driver' => 'pgsql',
 *             'database' => 'mydb',
 *             'username' => 'user',
 *             'password' => 'pass',
 *         ]);
 *
 *         try {
 *             $this->withTunnelRetry(function() {
 *                 // Your database operations here
 *                 DB::connection('pgsql_remote')->select('...');
 *             });
 *         } finally {
 *             $this->closeTunnel();
 *         }
 *     }
 * }
 */
trait ManagesTunnel
{
    /**
     * Tunnel instance
     *
     * @var Tunnel|null
     */
    protected ?Tunnel $tunnel = null;

    /**
     * Tunnel connection instance
     *
     * @var TunnelConnection|null
     */
    protected ?TunnelConnection $tunnelConnection = null;

    /**
     * Keep tunnel alive after command finishes
     *
     * @var bool
     */
    protected bool $keepTunnelAlive = false;

    /**
     * Database connection name for tunnel
     *
     * @var string|null
     */
    protected ?string $tunnelDbConnectionName = null;

    /**
     * Setup SSH tunnel with smart reuse and signal handlers
     *
     * This method:
     * 1. Creates or reuses existing tunnel
     * 2. Registers database connection if provided
     * 3. Sets up signal handlers for graceful shutdown
     * 4. Validates database accessibility
     *
     * @param string $connectionName Tunnel connection name from config/tunnel.php
     * @param array|null $dbConfig Database connection config (optional)
     * @param bool $keepAlive Keep tunnel running after command finishes
     * @param bool $validateDb Validate database connection after tunnel starts
     * @return TunnelConnection
     * @throws TunnelConnectionException
     */
    protected function setupTunnel(
        string $connectionName,
        ?array $dbConfig = null,
        bool $keepAlive = false,
        bool $validateDb = true
    ): TunnelConnection {
        $this->info("Setting up SSH tunnel '{$connectionName}'...");

        // Create tunnel instance
        $this->tunnel = Tunnel::connection($connectionName);

        // Register database connection if provided
        if ($dbConfig !== null && isset($dbConfig['connection_name'])) {
            $this->tunnelDbConnectionName = $dbConfig['connection_name'];
            unset($dbConfig['connection_name']);

            $this->tunnel->withDatabaseConnection(
                $this->tunnelDbConnectionName,
                $dbConfig
            );

            $this->line("  Database connection '{$this->tunnelDbConnectionName}' will be registered");
        }

        // Start or reuse tunnel
        $this->tunnelConnection = $this->tunnel->reuseOrCreate();

        // Set keep-alive flag
        $this->keepTunnelAlive = $keepAlive;
        if ($keepAlive) {
            $this->tunnelConnection->withKeepAlive(true);
            $this->line("  Tunnel will stay alive after command finishes");
        }

        // Setup signal handlers for graceful shutdown
        $this->tunnelConnection->setupSignalHandlers();

        $pid = $this->tunnelConnection->getPid();
        $port = $this->tunnelConnection->getConfig()->localPort;
        $this->info("✓ Tunnel ready (PID: {$pid}, Port: {$port})");

        // Validate database connection if requested
        if ($validateDb && $this->tunnelDbConnectionName) {
            $this->validateTunnelDatabase($this->tunnelDbConnectionName);
        }

        return $this->tunnelConnection;
    }

    /**
     * Ensure tunnel is connected, reconnect if needed
     *
     * @param int $maxAttempts Maximum reconnection attempts
     * @return bool true if tunnel is connected
     */
    protected function ensureTunnelConnected(int $maxAttempts = 3): bool
    {
        if (!$this->tunnelConnection) {
            $this->warn('Tunnel not initialized');
            return false;
        }

        if ($this->tunnelConnection->isRunning()) {
            return true;
        }

        $this->warn('Tunnel connection lost, attempting to reconnect...');

        $result = $this->tunnelConnection->ensureConnected($maxAttempts);

        if ($result) {
            $this->info('✓ Tunnel reconnected successfully');
        } else {
            $this->error('✗ Failed to reconnect tunnel');
        }

        return $result;
    }

    /**
     * Execute operation with automatic retry on connection errors
     *
     * If operation fails due to connection error, tunnel will be reconnected
     * and operation retried automatically.
     *
     * @param callable $operation Operation to execute
     * @param int $maxAttempts Maximum retry attempts
     * @return mixed Operation result
     * @throws \Exception
     */
    protected function withTunnelRetry(callable $operation, int $maxAttempts = 3)
    {
        if (!$this->tunnelConnection) {
            throw new \RuntimeException('Tunnel not initialized. Call setupTunnel() first.');
        }

        return $this->tunnelConnection->executeWithRetry($operation, $maxAttempts);
    }

    /**
     * Validate database accessibility through tunnel
     *
     * Executes SELECT 1 query to verify real database connection
     *
     * @param string $connectionName Database connection name
     * @param int $timeout Timeout in seconds
     * @param bool $wait Wait for database with retries
     * @return bool true if database is accessible
     */
    protected function validateTunnelDatabase(
        string $connectionName,
        int $timeout = 5,
        bool $wait = true
    ): bool {
        if (!$this->tunnelConnection) {
            $this->warn('Tunnel not initialized');
            return false;
        }

        $this->line("  Validating database connection '{$connectionName}'...");

        if ($wait) {
            $result = $this->tunnelConnection->waitForDatabase($connectionName, 5, 2);
        } else {
            $result = $this->tunnelConnection->validateDatabase($connectionName, $timeout);
        }

        if ($result) {
            $this->info("  ✓ Database '{$connectionName}' is accessible");
        } else {
            $this->error("  ✗ Database '{$connectionName}' is not accessible");
        }

        return $result;
    }

    /**
     * Perform full tunnel validation (process + port + database)
     *
     * @param string|null $connectionName Database connection to check (optional)
     * @return array ['valid' => bool, 'errors' => array]
     */
    protected function validateTunnel(?string $connectionName = null): array
    {
        if (!$this->tunnelConnection) {
            return [
                'valid' => false,
                'errors' => ['Tunnel not initialized'],
            ];
        }

        $result = $this->tunnelConnection->validate($connectionName);

        if ($result['valid']) {
            $this->info('✓ Tunnel validation passed');
        } else {
            $this->error('✗ Tunnel validation failed:');
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        return $result;
    }

    /**
     * Close tunnel gracefully
     *
     * Note: If keep-alive is enabled, tunnel will not be closed
     *
     * @return void
     */
    protected function closeTunnel(): void
    {
        if (!$this->tunnelConnection) {
            return;
        }

        if ($this->keepTunnelAlive) {
            $pid = $this->tunnelConnection->getPid();
            $this->info("Tunnel will stay alive (PID: {$pid})");
            return;
        }

        $this->line('Closing tunnel...');
        $this->tunnelConnection->stop();
        $this->info('✓ Tunnel closed');
    }

    /**
     * Get tunnel instance
     *
     * @return Tunnel|null
     */
    protected function getTunnel(): ?Tunnel
    {
        return $this->tunnel;
    }

    /**
     * Get tunnel connection instance
     *
     * @return TunnelConnection|null
     */
    protected function getTunnelConnection(): ?TunnelConnection
    {
        return $this->tunnelConnection;
    }

    /**
     * Check if tunnel is initialized and running
     *
     * @return bool
     */
    protected function isTunnelRunning(): bool
    {
        return $this->tunnelConnection && $this->tunnelConnection->isRunning();
    }
}
