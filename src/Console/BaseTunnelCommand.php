<?php

namespace ArtemYurov\Autossh\Console;

use Illuminate\Console\Command;

/**
 * Базовый класс для команд управления туннелями
 *
 * Абстрактный класс не регистрируется Laravel как команда
 */
abstract class BaseTunnelCommand extends Command
{
    /**
     * Показать список доступных туннелей из конфигурации
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
