<?php

namespace ArtemYurov\Autossh;

use Illuminate\Support\ServiceProvider;

class AutosshTunnelServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package config with application config
        $this->mergeConfigFrom(
            __DIR__.'/../config/tunnel.php',
            'tunnel'
        );

        // Register Tunnel class in container
        $this->app->bind('tunnel', function ($app) {
            // Return default tunnel connection
            return Tunnel::connection();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            // Publish config file
            $this->publishes([
                __DIR__.'/../config/tunnel.php' => config_path('tunnel.php'),
            ], 'tunnel-config');

            // Publish .env example
            $this->publishes([
                __DIR__.'/../.env.example.tunnel' => base_path('.env.example.tunnel'),
            ], 'tunnel-env');

            // Publish both config and env example together
            $this->publishes([
                __DIR__.'/../config/tunnel.php' => config_path('tunnel.php'),
                __DIR__.'/../.env.example.tunnel' => base_path('.env.example.tunnel'),
            ], 'tunnel');

            // Register Artisan commands
            $this->commands([
                \ArtemYurov\Autossh\Console\TunnelStartCommand::class,
                \ArtemYurov\Autossh\Console\TunnelStopCommand::class,
                \ArtemYurov\Autossh\Console\TunnelStatusCommand::class,
                \ArtemYurov\Autossh\Console\TunnelReuseCommand::class,
                \ArtemYurov\Autossh\Console\TunnelDiagnoseCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['tunnel'];
    }
}
