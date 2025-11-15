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
     */
    public static function connection(?string $name = null): self
    {
        $name = $name ?: config('tunnel.default');
        $connectionConfig = config("tunnel.connections.{$name}");

        if (!$connectionConfig) {
            throw new \InvalidArgumentException("Tunnel connection '{$name}' not found in config");
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

        $this->connection = new TunnelConnection($this->config);
        $this->connection->start();

        // Register database connection if specified
        if ($this->connectionName && $this->databaseConfig) {
            $this->registerDatabaseConnection();
        }

        return $this->connection;
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
}
