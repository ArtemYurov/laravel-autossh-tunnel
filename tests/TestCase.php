<?php

namespace ArtemYurov\Autossh\Tests;

use ArtemYurov\Autossh\AutosshTunnelServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AutosshTunnelServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default tunnel configuration for testing
        config()->set('tunnel', [
            'default' => 'test_db',
            'debug' => false,
            'autossh' => [
                'enabled' => true,
            ],
            'retry' => [
                'max_attempts' => 3,
                'delay' => 2,
                'exponential' => false,
            ],
            'validation' => [
                'port_timeout' => 1,
                'database_timeout' => 5,
                'database_max_attempts' => 5,
                'database_retry_delay' => 2,
            ],
            'signals' => [
                'enabled' => true,
                'handlers' => ['SIGINT', 'SIGTERM'],
            ],
            'reuse' => [
                'use_pid_file' => true,
                'use_port_scan' => true,
                'pid_directory' => sys_get_temp_dir() . '/laravel-autossh-tunnel-test',
            ],
            'connections' => [
                'test_db' => [
                    'type' => 'forward',
                    'user' => 'testuser',
                    'host' => 'test.example.com',
                    'port' => 22,
                    'identity_file' => null,
                    'remote_host' => 'localhost',
                    'remote_port' => 5432,
                    'local_host' => '127.0.0.1',
                    'local_port' => 15432,
                    'ssh_options' => [
                        'StrictHostKeyChecking' => false,
                        'ServerAliveInterval' => 60,
                        'ServerAliveCountMax' => 3,
                    ],
                ],
            ],
        ]);
    }
}
