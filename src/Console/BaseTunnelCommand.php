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
        if (!empty($availableConnections)) {
            $this->newLine();
            $this->info("Available tunnel connections in config/tunnel.php:");
            foreach ($availableConnections as $connection) {
                $this->line("  - {$connection}");
            }
        }
    }
}
