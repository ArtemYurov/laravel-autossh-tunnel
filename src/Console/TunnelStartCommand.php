<?php

namespace ArtemYurov\Autossh\Console;

use ArtemYurov\Autossh\Tunnel;
use ArtemYurov\Autossh\TunnelManager;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Terminal;

class TunnelStartCommand extends Command
{
    protected $signature = 'tunnel:start
                            {connection? : The tunnel connection name from config}
                            {--detach : Run tunnel in background without monitoring}';

    protected $description = 'Start SSH tunnel with live monitoring';

    protected bool $shouldStop = false;

    public function handle(): int
    {
        $connectionName = $this->argument('connection') ?? config('tunnel.default', 'remote');
        $manager = new TunnelManager();

        // Check if tunnel is already running
        if ($manager->getTunnelInfo($connectionName)) {
            $this->error("Tunnel '{$connectionName}' is already running!");
            $this->info("Use 'php artisan tunnel:stop {$connectionName}' to stop it first.");
            return self::FAILURE;
        }

        try {
            // Start tunnel
            $this->info("Starting tunnel: {$connectionName}");
            $tunnel = Tunnel::connection($connectionName);
            $connection = $tunnel->start();

            // Save tunnel info
            $manager->saveTunnel($connectionName, $connection);

            $config = $connection->getConfig();

            if ($this->option('detach')) {
                // Detached mode - just start and exit
                $this->newLine();
                $this->info('✓ Tunnel started in background');
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['PID', $connection->getPid()],
                        ['Connection', $connectionName],
                        ['Local Port', $config->localPort],
                        ['Remote', "{$config->remoteHost}:{$config->remotePort}"],
                        ['SSH', "{$config->user}@{$config->host}:{$config->port}"],
                    ]
                );
                $this->newLine();
                $this->info("Use 'php artisan tunnel:stop {$connectionName}' to stop the tunnel");

                return self::SUCCESS;
            }

            // Interactive monitoring mode
            $this->showHeader($connectionName, $connection);
            $this->setupSignalHandlers();

            // Monitoring loop
            while (!$this->shouldStop) {
                $this->updateStatus($connectionName, $manager, $connection);
                sleep(1);
            }

            // Cleanup on exit
            $this->newLine(2);
            $this->info('Stopping tunnel...');
            $connection->stop();
            $manager->removeTunnel($connectionName);
            $this->info('✓ Tunnel stopped');

            return self::SUCCESS;

        } catch (TunnelConfigException $e) {
            $this->newLine();
            $this->error($e->getMessage());
            $this->newLine();
            $this->info("Check your configuration in: config/tunnel.php");
            return self::FAILURE;
        } catch (TunnelConnectionException $e) {
            $this->error("Failed to start tunnel: {$e->getMessage()}");
            $manager->removeTunnel($connectionName);
            return self::FAILURE;
        }
    }

    protected function showHeader($connectionName, $connection): void
    {
        $config = $connection->getConfig();

        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════════╗');
        $this->line('║                     SSH Tunnel Monitor                         ║');
        $this->line('╠════════════════════════════════════════════════════════════════╣');
        $this->line(sprintf('║ Connection: %-50s ║', $connectionName));
        $this->line(sprintf('║ Local Port: %-50s ║', $config->localPort));
        $this->line(sprintf('║ Remote:     %-50s ║', "{$config->remoteHost}:{$config->remotePort}"));
        $this->line(sprintf('║ SSH:        %-50s ║', "{$config->user}@{$config->host}"));
        $this->line(sprintf('║ PID:        %-50s ║', $connection->getPid()));
        $this->line('╠════════════════════════════════════════════════════════════════╣');
        $this->line('║ Press Ctrl+C to stop the tunnel                                ║');
        $this->line('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    protected function updateStatus(string $connectionName, TunnelManager $manager, $connection): void
    {
        $info = $manager->getTunnelInfo($connectionName);

        if (!$info) {
            $this->error('Tunnel stopped unexpectedly!');
            $this->shouldStop = true;
            return;
        }

        $uptime = $manager->getUptime($info);
        $uptimeStr = $manager->formatUptime($uptime);
        $status = $connection->isRunning() ? '<fg=green>● ACTIVE</>' : '<fg=red>● INACTIVE</>';

        // Clear line and update
        $this->output->write("\r\033[K"); // Clear line
        $this->output->write("Status: {$status} | Uptime: {$uptimeStr} | PID: {$info['pid']}");
    }

    protected function setupSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, function () {
                $this->shouldStop = true;
            });
            pcntl_signal(SIGTERM, function () {
                $this->shouldStop = true;
            });
        }

        // Also handle in the monitoring loop
        $this->trap([SIGINT, SIGTERM], function () {
            $this->shouldStop = true;
        });
    }
}
