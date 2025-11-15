<?php

return [

    // Default tunnel name to be used when calling Tunnel::connection() without parameters
    'default' => env('TUNNEL_CONNECTION', 'remote_db'),

    // Enable detailed logging of SSH commands and tunnel processes (inherits app.debug by default)
    'debug' => env('TUNNEL_DEBUG', env('APP_DEBUG', false)),

    // AutoSSH configuration (automatically use autossh if available for auto-reconnection)
    'autossh' => [
        'enabled' => env('TUNNEL_AUTOSSH_ENABLED', true),
    ],

    /*
     * Tunnel Connections
     *
     * All SSH tunnels available in the application are defined here.
     * Similar to database connections, you can configure multiple tunnels and switch between them.
     *
     * Two types of tunnels:
     * - 'forward': Access remote services from local machine (ssh -L)
     * - 'reverse': Expose local services to remote server (ssh -R)
     *
     * Usage:
     *   Tunnel::connection('remote_db')->execute(function() { ... });
     *   Tunnel::connection('local_webhooks')->execute(function() { ... });
     */
    'connections' => [

        // Forward Tunnel - Access remote database from local machine
        'remote_db' => [
            'type' => 'forward',
            'user' => env('TUNNEL_SSH_USER'),
            'host' => env('TUNNEL_SSH_HOST'),
            'port' => env('TUNNEL_SSH_PORT', 22),
            'identity_file' => env('TUNNEL_SSH_KEY'),
            'remote_host' => env('TUNNEL_REMOTE_HOST', 'localhost'),
            'remote_port' => env('TUNNEL_REMOTE_PORT', 5432),
            'local_host' => env('TUNNEL_LOCAL_HOST', '127.0.0.1'),
            'local_port' => env('TUNNEL_LOCAL_PORT', 15432),
            'ssh_options' => [
                'StrictHostKeyChecking' => env('TUNNEL_SSH_STRICT_HOST_KEY_CHECKING', false),
                'ServerAliveInterval' => env('TUNNEL_SSH_SERVER_ALIVE_INTERVAL', 60),
                'ServerAliveCountMax' => env('TUNNEL_SSH_SERVER_ALIVE_COUNT_MAX', 3),
                'ExitOnForwardFailure' => env('TUNNEL_SSH_EXIT_ON_FORWARD_FAILURE', true),
                'TCPKeepAlive' => env('TUNNEL_SSH_TCP_KEEP_ALIVE', true),
                'ConnectTimeout' => env('TUNNEL_SSH_CONNECT_TIMEOUT', 10),
            ],
        ],

        // Reverse Tunnel - Expose local application for webhook testing
        'local_webhooks' => [
            'type' => 'reverse',
            'user' => env('WEBHOOK_SSH_USER'),
            'host' => env('WEBHOOK_SSH_HOST'),
            'port' => env('WEBHOOK_SSH_PORT', 22),
            'identity_file' => env('WEBHOOK_SSH_KEY'),
            'remote_host' => env('WEBHOOK_REMOTE_HOST', 'localhost'),
            'remote_port' => env('WEBHOOK_REMOTE_PORT', 8080),
            'local_host' => env('WEBHOOK_LOCAL_HOST', '127.0.0.1'),
            'local_port' => env('WEBHOOK_LOCAL_PORT', 8000),
            'ssh_options' => [
                'StrictHostKeyChecking' => env('WEBHOOK_SSH_STRICT_HOST_KEY_CHECKING', false),
                'ServerAliveInterval' => env('WEBHOOK_SSH_SERVER_ALIVE_INTERVAL', 60),
                'ServerAliveCountMax' => env('WEBHOOK_SSH_SERVER_ALIVE_COUNT_MAX', 3),
                'ExitOnForwardFailure' => env('WEBHOOK_SSH_EXIT_ON_FORWARD_FAILURE', true),
                'TCPKeepAlive' => env('WEBHOOK_SSH_TCP_KEEP_ALIVE', true),
                'ConnectTimeout' => env('WEBHOOK_SSH_CONNECT_TIMEOUT', 10),
            ],
        ],

    ],

];
