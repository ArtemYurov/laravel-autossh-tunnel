<?php

namespace ArtemYurov\Autossh\Console;

use ArtemYurov\Autossh\Tunnel;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;

/**
 * Command to find and reuse existing SSH tunnel
 *
 * This command searches for existing SSH tunnel by:
 * 1. PID file
 * 2. Port (using lsof/netstat)
 *
 * If tunnel is found, it can optionally register database connection
 * for usage in the application.
 */
class TunnelReuseCommand extends BaseTunnelCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tunnel:reuse
                            {connection? : Tunnel connection name from config/tunnel.php}
                            {--db-connection= : Database connection name to register}
                            {--db-driver=pgsql : Database driver (pgsql, mysql)}
                            {--db-database= : Database name}
                            {--db-username= : Database username}
                            {--db-password= : Database password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and reuse existing SSH tunnel without creating a new one';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $connectionName = $this->argument('connection') ?: config('tunnel.default');

        if (!$connectionName) {
            $this->error('Tunnel connection name not specified and default connection not configured.');
            $this->showAvailableConnections();
            return self::FAILURE;
        }

        try {
            // Create tunnel instance
            $tunnel = Tunnel::connection($connectionName);

            // Register database connection if provided
            if ($this->option('db-connection')) {
                $dbConfig = [
                    'driver' => $this->option('db-driver'),
                    'database' => $this->option('db-database'),
                    'username' => $this->option('db-username'),
                    'password' => $this->option('db-password'),
                ];

                // Filter out null values
                $dbConfig = array_filter($dbConfig, fn($value) => $value !== null);

                if (empty($dbConfig['database'])) {
                    $this->error('--db-database option is required when using --db-connection');
                    return self::FAILURE;
                }

                $tunnel->withDatabaseConnection(
                    $this->option('db-connection'),
                    $dbConfig
                );

                $this->line("Database connection '{$this->option('db-connection')}' will be registered");
            }

            $this->info("Searching for existing SSH tunnel '{$connectionName}'...");

            // Try to find and reuse existing tunnel
            $connection = $tunnel->reuseOrCreate();

            $pid = $connection->getPid();
            $config = $connection->getConfig();

            $this->newLine();
            $this->info('✓ Tunnel found and ready to use:');
            $this->line("  PID: {$pid}");
            $this->line("  Local: {$config->localHost}:{$config->localPort}");
            $this->line("  Remote: {$config->user}@{$config->host}:{$config->port}");
            $this->line("  Target: {$config->remoteHost}:{$config->remotePort}");

            if ($this->option('db-connection')) {
                $this->newLine();
                $this->info("Database connection '{$this->option('db-connection')}' registered and accessible at:");
                $this->line("  Host: {$config->localHost}");
                $this->line("  Port: {$config->localPort}");
            }

            $this->newLine();
            $this->comment('Note: This tunnel will stay alive after this command finishes.');
            $this->comment("To stop it, use: php artisan tunnel:stop {$connectionName}");

            return self::SUCCESS;

        } catch (TunnelConfigException $e) {
            $this->error($e->getMessage());
            $this->showAvailableConnections();
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Failed to reuse tunnel: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
