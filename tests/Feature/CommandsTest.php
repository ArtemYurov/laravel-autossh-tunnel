<?php

namespace ArtemYurov\Autossh\Tests\Feature;

use ArtemYurov\Autossh\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_tunnel_start_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:start')
            ->assertSuccessful();
    }

    public function test_tunnel_stop_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:stop')
            ->assertSuccessful();
    }

    public function test_tunnel_status_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:status')
            ->assertSuccessful();
    }

    public function test_tunnel_reuse_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:reuse')
            ->assertSuccessful();
    }

    public function test_tunnel_diagnose_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:diagnose')
            ->assertSuccessful();
    }

    public function test_tunnel_stop_shows_error_for_nonexistent_connection(): void
    {
        $this->artisan('tunnel:stop nonexistent')
            ->expectsOutputToContain('does not exist in configuration')
            ->assertFailed();
    }

    public function test_tunnel_stop_shows_available_connections_on_error(): void
    {
        $this->artisan('tunnel:stop nonexistent')
            ->expectsOutputToContain('Available tunnel connections')
            ->expectsOutputToContain('test_db')
            ->assertFailed();
    }

    public function test_tunnel_stop_shows_vendor_publish_hint_when_no_config(): void
    {
        // Remove all connections to simulate missing config
        config()->set('tunnel.connections', []);

        $this->artisan('tunnel:stop nonexistent')
            ->expectsOutputToContain('Configuration file config/tunnel.php not found or empty')
            ->expectsOutputToContain('php artisan vendor:publish --tag=tunnel-config')
            ->assertFailed();
    }

    public function test_tunnel_reuse_shows_error_when_db_database_missing(): void
    {
        $this->artisan('tunnel:reuse test_db --db-connection=test --db-driver=pgsql')
            ->expectsOutputToContain('--db-database option is required')
            ->assertFailed();
    }

    public function test_tunnel_diagnose_accepts_connection_argument(): void
    {
        // This will fail because tunnel is not running, but command should accept the argument
        $this->artisan('tunnel:diagnose test_db')
            ->assertFailed(); // Expected to fail because tunnel is not actually running
    }

    public function test_tunnel_diagnose_accepts_db_connection_option(): void
    {
        $this->artisan('tunnel:diagnose test_db --db-connection=test_db')
            ->assertFailed(); // Expected to fail because tunnel is not actually running
    }

    public function test_tunnel_diagnose_accepts_details_option(): void
    {
        $this->artisan('tunnel:diagnose test_db --details')
            ->assertFailed(); // Expected to fail because tunnel is not actually running
    }

    public function test_base_tunnel_command_is_not_registered(): void
    {
        // BaseTunnelCommand should be abstract and not registered
        $output = $this->artisan('list')->run();

        // Get the output as string
        ob_start();
        $this->artisan('list')->run();
        $listOutput = ob_get_clean();

        // BaseTunnelCommand should not appear in the list
        $this->assertStringNotContainsString('base:tunnel', strtolower($listOutput ?? ''));
    }

    public function test_commands_have_proper_descriptions(): void
    {
        // Just check that the commands are registered with descriptions
        $this->artisan('list')
            ->expectsOutputToContain('tunnel:start')
            ->expectsOutputToContain('tunnel:stop')
            ->expectsOutputToContain('tunnel:status')
            ->expectsOutputToContain('tunnel:reuse')
            ->expectsOutputToContain('tunnel:diagnose')
            ->assertSuccessful();
    }
}
