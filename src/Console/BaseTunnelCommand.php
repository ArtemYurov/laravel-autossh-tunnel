<?php

namespace ArtemYurov\Autossh\Console;

use Illuminate\Console\Command;

/**
 * Base class for tunnel management commands
 *
 * Abstract class is not registered by Laravel as a command
 */
abstract class BaseTunnelCommand extends Command
{
    /**
     * Show available tunnels from configuration
     */
    protected function showAvailableConnections(): void
    {
        $availableConnections = array_keys(config('tunnel.connections', []));

        $this->newLine();

        if (empty($availableConnections)) {
            $this->warn("Configuration file config/tunnel.php not found or empty.");
            $this->newLine();
            $this->info("Please publish the configuration file:");
            $this->line("  php artisan vendor:publish --tag=tunnel-config");
        } else {
            $this->info("Available tunnel connections in config/tunnel.php:");
            foreach ($availableConnections as $connection) {
                $this->line("  - {$connection}");
            }
        }
    }
}
