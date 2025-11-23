<?php

namespace ArtemYurov\Autossh\Tests\Feature;

use ArtemYurov\Autossh\DTO\TunnelConfig;
use ArtemYurov\Autossh\Enums\TunnelType;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;
use ArtemYurov\Autossh\Tests\TestCase;
use ArtemYurov\Autossh\Tunnel;

class TunnelConfigurationTest extends TestCase
{
    public function test_can_create_tunnel_from_config(): void
    {
        $tunnel = Tunnel::connection('test_db');

        $this->assertInstanceOf(Tunnel::class, $tunnel);
        $this->assertInstanceOf(TunnelConfig::class, $tunnel->getConfig());
    }

    public function test_throws_exception_when_connection_not_found(): void
    {
        $this->expectException(TunnelConfigException::class);
        $this->expectExceptionMessage('does not exist in configuration');

        Tunnel::connection('nonexistent');
    }

    public function test_throws_exception_when_no_default_connection(): void
    {
        config()->set('tunnel.default', null);

        $this->expectException(TunnelConfigException::class);
        $this->expectExceptionMessage('not specified and default connection not configured');

        Tunnel::connection();
    }

    public function test_throws_exception_when_required_params_missing(): void
    {
        config()->set('tunnel.connections.invalid', [
            'type' => 'forward',
            // Missing 'user' and 'host'
            'port' => 22,
        ]);

        $this->expectException(TunnelConfigException::class);
        $this->expectExceptionMessage('Required parameter');

        Tunnel::connection('invalid');
    }

    public function test_can_create_tunnel_from_config_object(): void
    {
        $config = new TunnelConfig(
            type: TunnelType::Forward,
            user: 'testuser',
            host: 'test.example.com',
            port: 22,
            identityFile: null,
            remoteHost: 'localhost',
            remotePort: 5432,
            localHost: '127.0.0.1',
            localPort: 15432,
            sshOptions: []
        );

        $tunnel = Tunnel::fromConfig($config);

        $this->assertInstanceOf(Tunnel::class, $tunnel);
        $this->assertEquals($config, $tunnel->getConfig());
    }

    public function test_config_uses_default_values(): void
    {
        config()->set('tunnel.connections.minimal', [
            'type' => 'forward',
            'user' => 'testuser',
            'host' => 'test.example.com',
            // All other params should use defaults
        ]);

        $tunnel = Tunnel::connection('minimal');
        $config = $tunnel->getConfig();

        $this->assertEquals(22, $config->port);
        $this->assertEquals('localhost', $config->remoteHost);
        $this->assertEquals(5432, $config->remotePort);
        $this->assertEquals('127.0.0.1', $config->localHost);
        $this->assertEquals(15432, $config->localPort);
    }

    public function test_can_set_database_connection(): void
    {
        $tunnel = Tunnel::connection('test_db');

        $result = $tunnel->withDatabaseConnection('test_connection', [
            'driver' => 'pgsql',
            'database' => 'testdb',
            'username' => 'testuser',
            'password' => 'testpass',
        ]);

        // Method should return self for chaining
        $this->assertInstanceOf(Tunnel::class, $result);

        // Database connection is only registered after start() is called
        // So we just verify the method works without errors
        $this->assertTrue(true);
    }

    public function test_tunnel_type_can_be_forward(): void
    {
        config()->set('tunnel.connections.forward_tunnel', [
            'type' => 'forward',
            'user' => 'testuser',
            'host' => 'test.example.com',
        ]);

        $tunnel = Tunnel::connection('forward_tunnel');

        $this->assertEquals(TunnelType::Forward, $tunnel->getConfig()->type);
    }

    public function test_tunnel_type_can_be_reverse(): void
    {
        config()->set('tunnel.connections.reverse_tunnel', [
            'type' => 'reverse',
            'user' => 'testuser',
            'host' => 'test.example.com',
        ]);

        $tunnel = Tunnel::connection('reverse_tunnel');

        $this->assertEquals(TunnelType::Reverse, $tunnel->getConfig()->type);
    }

    public function test_ssh_options_are_properly_configured(): void
    {
        config()->set('tunnel.connections.with_options', [
            'type' => 'forward',
            'user' => 'testuser',
            'host' => 'test.example.com',
            'ssh_options' => [
                'StrictHostKeyChecking' => false,
                'ServerAliveInterval' => 30,
                'ConnectTimeout' => 5,
            ],
        ]);

        $tunnel = Tunnel::connection('with_options');
        $config = $tunnel->getConfig();

        $this->assertArrayHasKey('StrictHostKeyChecking', $config->sshOptions);
        $this->assertFalse($config->sshOptions['StrictHostKeyChecking']);
        $this->assertEquals(30, $config->sshOptions['ServerAliveInterval']);
        $this->assertEquals(5, $config->sshOptions['ConnectTimeout']);
    }

    public function test_identity_file_can_be_specified(): void
    {
        // Create temporary key file for testing
        $tempKey = tempnam(sys_get_temp_dir(), 'ssh_key_');
        file_put_contents($tempKey, 'fake ssh key');

        config()->set('tunnel.connections.with_key', [
            'type' => 'forward',
            'user' => 'testuser',
            'host' => 'test.example.com',
            'identity_file' => $tempKey,
        ]);

        $tunnel = Tunnel::connection('with_key');
        $config = $tunnel->getConfig();

        $this->assertEquals($tempKey, $config->identityFile);

        // Cleanup
        unlink($tempKey);
    }

    public function test_config_identifier_is_unique(): void
    {
        config()->set('tunnel.connections.tunnel1', [
            'type' => 'forward',
            'user' => 'user1',
            'host' => 'host1.example.com',
            'local_port' => 15432,
        ]);

        config()->set('tunnel.connections.tunnel2', [
            'type' => 'forward',
            'user' => 'user2',
            'host' => 'host2.example.com',
            'local_port' => 15433,
        ]);

        $tunnel1 = Tunnel::connection('tunnel1');
        $tunnel2 = Tunnel::connection('tunnel2');

        $id1 = $tunnel1->getConfig()->getIdentifier();
        $id2 = $tunnel2->getConfig()->getIdentifier();

        $this->assertNotEquals($id1, $id2);
    }
}
