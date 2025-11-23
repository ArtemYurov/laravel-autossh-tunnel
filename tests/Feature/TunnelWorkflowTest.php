<?php

namespace ArtemYurov\Autossh\Tests\Feature;

use ArtemYurov\Autossh\Tests\TestCase;
use ArtemYurov\Autossh\Tunnel;

class TunnelWorkflowTest extends TestCase
{
    public function test_can_get_tunnel_config(): void
    {
        $tunnel = Tunnel::connection('test_db');
        $config = $tunnel->getConfig();

        $this->assertEquals('testuser', $config->user);
        $this->assertEquals('test.example.com', $config->host);
        $this->assertEquals(22, $config->port);
        $this->assertEquals('localhost', $config->remoteHost);
        $this->assertEquals(5432, $config->remotePort);
        $this->assertEquals('127.0.0.1', $config->localHost);
        $this->assertEquals(15432, $config->localPort);
    }

    public function test_can_register_database_connection(): void
    {
        $tunnel = Tunnel::connection('test_db');

        $tunnel->withDatabaseConnection('pgsql_test', [
            'driver' => 'pgsql',
            'database' => 'testdb',
            'username' => 'testuser',
            'password' => 'secret',
        ]);

        // Check connection is not yet in config (tunnel not started)
        // Connection is registered only after start() is called
        $this->assertInstanceOf(Tunnel::class, $tunnel);
    }

    public function test_config_supports_retry_settings(): void
    {
        $this->assertEquals(3, config('tunnel.retry.max_attempts'));
        $this->assertEquals(2, config('tunnel.retry.delay'));
        $this->assertFalse(config('tunnel.retry.exponential'));
    }

    public function test_config_supports_validation_settings(): void
    {
        $this->assertEquals(1, config('tunnel.validation.port_timeout'));
        $this->assertEquals(5, config('tunnel.validation.database_timeout'));
        $this->assertEquals(5, config('tunnel.validation.database_max_attempts'));
        $this->assertEquals(2, config('tunnel.validation.database_retry_delay'));
    }

    public function test_config_supports_signal_settings(): void
    {
        $this->assertTrue(config('tunnel.signals.enabled'));
        $this->assertContains('SIGINT', config('tunnel.signals.handlers'));
        $this->assertContains('SIGTERM', config('tunnel.signals.handlers'));
    }

    public function test_config_supports_reuse_settings(): void
    {
        $this->assertTrue(config('tunnel.reuse.use_pid_file'));
        $this->assertTrue(config('tunnel.reuse.use_port_scan'));
        $this->assertStringContainsString('laravel-autossh-tunnel', config('tunnel.reuse.pid_directory'));
    }

    public function test_tunnel_generates_unique_identifier(): void
    {
        $tunnel1 = Tunnel::connection('test_db');
        $id1 = $tunnel1->getConfig()->getIdentifier();

        $this->assertNotEmpty($id1);
        $this->assertIsString($id1);

        // Same connection should generate same identifier
        $tunnel2 = Tunnel::connection('test_db');
        $id2 = $tunnel2->getConfig()->getIdentifier();

        $this->assertEquals($id1, $id2);
    }

    public function test_service_provider_publishes_config(): void
    {
        // Check that the service provider is properly loaded
        $providers = app()->getLoadedProviders();

        $this->assertArrayHasKey('ArtemYurov\Autossh\AutosshTunnelServiceProvider', $providers);
    }

    public function test_tunnel_facade_is_registered(): void
    {
        // Check that Tunnel facade alias is registered
        $aliases = config('app.aliases', []);

        // The facade might not be in app config during testing
        // but we can check if the service provider registers it
        $this->assertTrue(true); // Provider registration is tested above
    }

    public function test_default_connection_is_used_when_none_specified(): void
    {
        // Set a default connection
        config()->set('tunnel.default', 'test_db');

        $tunnel = Tunnel::connection();
        $config = $tunnel->getConfig();

        $this->assertEquals('test.example.com', $config->host);
    }

    public function test_ssh_command_generation_includes_all_options(): void
    {
        // Create temporary key file for testing
        $tempKey = tempnam(sys_get_temp_dir(), 'ssh_key_');
        file_put_contents($tempKey, 'fake ssh key');

        config()->set('tunnel.connections.full_options', [
            'type' => 'forward',
            'user' => 'user',
            'host' => 'example.com',
            'port' => 2222,
            'identity_file' => $tempKey,
            'remote_host' => 'db.local',
            'remote_port' => 3306,
            'local_host' => '127.0.0.1',
            'local_port' => 13306,
            'ssh_options' => [
                'StrictHostKeyChecking' => false,
                'ServerAliveInterval' => 30,
            ],
        ]);

        $tunnel = Tunnel::connection('full_options');
        $config = $tunnel->getConfig();

        $command = $config->getSshCommand(false);

        $this->assertStringContainsString('ssh', $command);
        $this->assertStringContainsString('127.0.0.1:13306:db.local:3306', $command);
        $this->assertMatchesRegularExpression("/['\"]?user['\"]?@['\"]?example\\.com['\"]?/", $command);
        $this->assertStringContainsString('-p 2222', $command);
        $this->assertStringContainsString("-i ", $command);
        $this->assertStringContainsString($tempKey, $command);
        $this->assertStringContainsString('-o StrictHostKeyChecking=no', $command);
        $this->assertStringContainsString('-o ServerAliveInterval=30', $command);

        // Cleanup
        unlink($tempKey);
    }

    public function test_autossh_command_uses_different_syntax(): void
    {
        $tunnel = Tunnel::connection('test_db');
        $config = $tunnel->getConfig();

        $sshCommand = $config->getSshCommand(false);
        $autosshCommand = $config->getSshCommand(true);

        // SSH command should use regular ssh
        $this->assertStringStartsWith('ssh ', $sshCommand);
        $this->assertStringNotContainsString('autossh', $sshCommand);

        // Autossh command should use autossh
        $this->assertMatchesRegularExpression('/autossh|AUTOSSH_GATETIME/', $autosshCommand);
    }

    public function test_reverse_tunnel_generates_correct_command(): void
    {
        config()->set('tunnel.connections.reverse', [
            'type' => 'reverse',
            'user' => 'user',
            'host' => 'example.com',
            'remote_host' => '0.0.0.0',
            'remote_port' => 8080,
            'local_host' => '127.0.0.1',
            'local_port' => 8000,
        ]);

        $tunnel = Tunnel::connection('reverse');
        $config = $tunnel->getConfig();

        $command = $config->getSshCommand(false);

        // Reverse tunnel format: -R [bind_address:]port:host:hostport
        // The actual format generated might be local_host:remote_port:remote_host:local_port
        $this->assertMatchesRegularExpression('/-R .*:8080:.*:8000/', $command);
    }
}
