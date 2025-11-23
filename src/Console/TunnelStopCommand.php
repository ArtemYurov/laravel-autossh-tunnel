<?php

namespace ArtemYurov\Autossh\Console;

use ArtemYurov\Autossh\TunnelManager;
use Illuminate\Console\Command;

class TunnelStopCommand extends Command
{
    protected $signature = 'tunnel:stop
                            {connection? : The tunnel connection name to stop}
                            {--all : Stop all running tunnels}';

    protected $description = 'Stop running SSH tunnel';

    public function handle(): int
    {
        $manager = new TunnelManager();

        if ($this->option('all')) {
            return $this->stopAllTunnels($manager);
        }

        $connectionName = $this->argument('connection') ?? config('tunnel.default', 'remote');

        return $this->stopTunnel($connectionName, $manager);
    }

    protected function stopTunnel(string $connectionName, TunnelManager $manager): int
    {
        $info = $manager->getTunnelInfo($connectionName);

        if (!$info) {
            $this->error("Tunnel '{$connectionName}' is not running.");

            // Show available configured tunnels
            $availableConnections = array_keys(config('tunnel.connections', []));
            if (!empty($availableConnections)) {
                $this->newLine();
                $this->info("Available tunnel connections in config/tunnel.php:");
                foreach ($availableConnections as $connection) {
                    $this->line("  - {$connection}");
                }
            }

            return self::FAILURE;
        }

        $this->info("Stopping tunnel: {$connectionName}");
        $this->line("  PID: {$info['pid']}");
        $this->line("  Local Port: {$info['config']['local_port']}");
        $this->line("  Uptime: " . $manager->formatUptime($manager->getUptime($info)));

        if ($manager->stopTunnel($connectionName)) {
            $this->newLine();
            $this->info('✓ Tunnel stopped successfully');
            return self::SUCCESS;
        }

        $this->error('✗ Failed to stop tunnel');
        return self::FAILURE;
    }

    protected function stopAllTunnels(TunnelManager $manager): int
    {
        $tunnels = $manager->getAllTunnels();

        if (empty($tunnels)) {
            $this->info('No running tunnels found.');
            return self::SUCCESS;
        }

        $this->info('Stopping ' . count($tunnels) . ' tunnel(s)...');
        $this->newLine();

        $failed = 0;

        foreach ($tunnels as $connectionName => $info) {
            $this->line("Stopping: {$connectionName}");

            if (!$manager->stopTunnel($connectionName)) {
                $this->error("  ✗ Failed");
                $failed++;
            } else {
                $this->info("  ✓ Stopped");
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->info('✓ All tunnels stopped successfully');
            return self::SUCCESS;
        }

        $this->warn("⚠ {$failed} tunnel(s) failed to stop");
        return self::FAILURE;
    }
}
