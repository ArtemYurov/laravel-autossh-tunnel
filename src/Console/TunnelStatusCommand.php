<?php

namespace ArtemYurov\Autossh\Console;

use ArtemYurov\Autossh\TunnelManager;

class TunnelStatusCommand extends BaseTunnelCommand
{
    protected $signature = 'tunnel:status
                            {connection? : The tunnel connection name to check}
                            {--all : Show status of all tunnels}';

    protected $description = 'Show SSH tunnel status';

    public function handle(): int
    {
        $manager = new TunnelManager();

        if ($this->option('all')) {
            return $this->showAllTunnels($manager);
        }

        $connectionName = $this->argument('connection') ?? config('tunnel.default', 'remote');

        return $this->showTunnelStatus($connectionName, $manager);
    }

    protected function showTunnelStatus(string $connectionName, TunnelManager $manager): int
    {
        $info = $manager->getTunnelInfo($connectionName);

        if (!$info) {
            $this->warn("Tunnel '{$connectionName}' is not running.");
            return self::FAILURE;
        }

        $uptime = $manager->getUptime($info);
        $uptimeStr = $manager->formatUptime($uptime);

        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════════╗');
        $this->line(sprintf('║ Tunnel: %-54s ║', $connectionName));
        $this->line('╠════════════════════════════════════════════════════════════════╣');
        $this->line(sprintf('║ Status:       <fg=green>● ACTIVE</>%-42s ║', ''));
        $this->line(sprintf('║ PID:          %-49s ║', $info['pid']));
        $this->line(sprintf('║ Uptime:       %-49s ║', $uptimeStr));
        $this->line(sprintf('║ Started:      %-49s ║', date('Y-m-d H:i:s', $info['started_at'])));
        $this->line('╠════════════════════════════════════════════════════════════════╣');
        $this->line(sprintf('║ Local Port:   %-49s ║', $info['config']['local_port']));
        $this->line(sprintf('║ Remote:       %-49s ║', "{$info['config']['remote_host']}:{$info['config']['remote_port']}"));
        $this->line(sprintf('║ SSH:          %-49s ║', "{$info['config']['user']}@{$info['config']['host']}"));
        $this->line('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function showAllTunnels(TunnelManager $manager): int
    {
        $tunnels = $manager->getAllTunnels();

        if (empty($tunnels)) {
            $this->info('No running tunnels found.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Running Tunnels: ' . count($tunnels));
        $this->newLine();

        $rows = [];

        foreach ($tunnels as $connectionName => $info) {
            $uptime = $manager->formatUptime($manager->getUptime($info));

            $rows[] = [
                $connectionName,
                '<fg=green>● ACTIVE</>',
                $info['pid'],
                $uptime,
                $info['config']['local_port'],
                "{$info['config']['remote_host']}:{$info['config']['remote_port']}",
            ];
        }

        $this->table(
            ['Connection', 'Status', 'PID', 'Uptime', 'Local Port', 'Remote'],
            $rows
        );

        $this->newLine();

        return self::SUCCESS;
    }
}
