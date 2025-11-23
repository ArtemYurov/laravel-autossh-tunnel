<?php

namespace ArtemYurov\Autossh\Console;

use ArtemYurov\Autossh\Tunnel;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;
use ArtemYurov\Autossh\Services\ProcessManager;
use ArtemYurov\Autossh\Services\ConnectionValidator;

/**
 * Command to diagnose SSH tunnel health
 *
 * Performs comprehensive tunnel validation:
 * 1. Check if tunnel exists in configuration
 * 2. Check if tunnel process is running (PID file or port scan)
 * 3. Verify it's an SSH process
 * 4. Check port accessibility
 * 5. Optionally check database accessibility
 */
class TunnelDiagnoseCommand extends BaseTunnelCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tunnel:diagnose
                            {connection? : Tunnel connection name from config/tunnel.php}
                            {--db-connection= : Database connection name to check}
                            {--verbose : Show detailed diagnostic information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose SSH tunnel health and connectivity';

    /**
     * Process manager instance
     *
     * @var ProcessManager
     */
    protected ProcessManager $processManager;

    /**
     * Connection validator instance
     *
     * @var ConnectionValidator
     */
    protected ConnectionValidator $validator;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->processManager = new ProcessManager();
        $this->validator = new ConnectionValidator($this->processManager);
    }

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

        $this->info("Diagnosing SSH tunnel '{$connectionName}'...");
        $this->newLine();

        try {
            // Step 1: Check configuration
            $tunnel = Tunnel::connection($connectionName);
            $config = $tunnel->getConfig();

            $this->line('✓ Configuration found');
            if ($this->option('verbose')) {
                $this->line("  Local: {$config->localHost}:{$config->localPort}");
                $this->line("  Remote: {$config->user}@{$config->host}:{$config->port}");
                $this->line("  Target: {$config->remoteHost}:{$config->remotePort}");
                $this->newLine();
            }

            // Step 2: Search for tunnel process
            $this->line('Searching for tunnel process...');

            // Try PID file first
            $pidByFile = $this->findByPidFile($tunnel);

            // Try port scan
            $pidByPort = $tunnel->findExistingByPort();

            if (!$pidByFile && !$pidByPort) {
                $this->error('✗ Tunnel process not found');
                $this->line('  Neither PID file nor port scan found the tunnel');
                $this->newLine();
                $this->comment("To start tunnel: php artisan tunnel:start {$connectionName}");
                return self::FAILURE;
            }

            $pid = $pidByFile ?? $pidByPort;
            $foundBy = $pidByFile ? 'PID file' : 'port scan';

            $this->info("✓ Tunnel process found (via {$foundBy})");
            $this->line("  PID: {$pid}");

            // Step 3: Get process info
            $processInfo = $this->processManager->getProcessInfo($pid);
            if (!empty($processInfo)) {
                $this->line("  Process: {$processInfo['name']}");
                if ($this->option('verbose') && !empty($processInfo['command'])) {
                    $this->line("  Command: {$processInfo['command']}");
                }
            }

            // Step 4: Verify it's SSH
            if (!$this->processManager->isSshProcess($pid)) {
                $this->error('✗ Warning: Process is not an SSH process');
                return self::FAILURE;
            }
            $this->line('  ✓ Verified as SSH process');

            // Step 5: Check port accessibility
            $this->newLine();
            $this->line('Checking port accessibility...');

            if (!$this->validator->isPortAccessible($config->localPort, $config->localHost)) {
                $this->error("✗ Port {$config->localPort} is not accessible");
                return self::FAILURE;
            }

            $this->info("✓ Port {$config->localPort} is accessible");

            // Step 6: Check database if specified
            if ($this->option('db-connection')) {
                $this->newLine();
                $this->line("Checking database connection '{$this->option('db-connection')}'...");

                if ($this->validator->isDatabaseAccessible($this->option('db-connection'))) {
                    $this->info("✓ Database '{$this->option('db-connection')}' is accessible");
                } else {
                    $this->error("✗ Database '{$this->option('db-connection')}' is not accessible");

                    if ($this->option('verbose')) {
                        $error = $this->validator->getDatabaseConnectionError($this->option('db-connection'));
                        $this->line("  Error: {$error}");
                    }

                    return self::FAILURE;
                }
            }

            // Summary
            $this->newLine();
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info('✓ Tunnel is healthy and fully operational');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            return self::SUCCESS;

        } catch (TunnelConfigException $e) {
            $this->error($e->getMessage());
            $this->showAvailableConnections();
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Diagnostic failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Find tunnel PID by PID file using reflection
     *
     * @param Tunnel $tunnel
     * @return int|null
     */
    protected function findByPidFile(Tunnel $tunnel): ?int
    {
        try {
            // Use reflection to access protected method
            $reflection = new \ReflectionClass($tunnel);
            $method = $reflection->getMethod('readPidFile');
            $method->setAccessible(true);

            return $method->invoke($tunnel);
        } catch (\Exception $e) {
            return null;
        }
    }
}
