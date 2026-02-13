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
     * Retry Configuration
     *
     * Configure retry behavior when tunnel operations fail due to connection issues.
     * These settings apply to database operations executed through executeWithRetry().
     */
    'retry' => [
        // Maximum number of retry attempts
        'max_attempts' => env('TUNNEL_RETRY_MAX_ATTEMPTS', 3),

        // Delay between retry attempts in seconds
        'delay' => env('TUNNEL_RETRY_DELAY', 2),

        // Use exponential backoff for retry delays (2s, 4s, 8s, 16s...)
        'exponential' => env('TUNNEL_RETRY_EXPONENTIAL', false),
    ],

    /*
     * Connection Validation
     *
     * Configure how tunnel connections are validated for health and accessibility.
     */
    'validation' => [
        // Timeout for port accessibility checks in seconds
        'port_timeout' => env('TUNNEL_VALIDATION_PORT_TIMEOUT', 1),

        // Maximum attempts to wait for port to become accessible after tunnel start (1 attempt = 1 second).
        // Increase for SSH agents with interactive confirmation (Secretive, Touch ID).
        'port_max_attempts' => env('TUNNEL_VALIDATION_PORT_MAX_ATTEMPTS', 10),

        // Timeout for database connection checks in seconds
        'database_timeout' => env('TUNNEL_VALIDATION_DATABASE_TIMEOUT', 5),

        // Maximum attempts when waiting for database to become available
        'database_max_attempts' => env('TUNNEL_VALIDATION_DATABASE_MAX_ATTEMPTS', 5),

        // Delay between database availability check attempts in seconds
        'database_retry_delay' => env('TUNNEL_VALIDATION_DATABASE_RETRY_DELAY', 2),
    ],

    /*
     * Signal Handling
     *
     * Configure how tunnels respond to system signals (SIGINT, SIGTERM).
     * Requires pcntl extension to be enabled.
     */
    'signals' => [
        // Enable automatic signal handlers for graceful shutdown
        'enabled' => env('TUNNEL_SIGNALS_ENABLED', true),

        // Signals to handle (SIGINT = Ctrl+C, SIGTERM = termination)
        'handlers' => [
            'SIGINT',   // Interrupt (Ctrl+C)
            'SIGTERM',  // Terminate
        ],
    ],

    /*
     * Tunnel Reuse
     *
     * Configure how existing tunnels are discovered and reused.
     */
    'reuse' => [
        // Enable tunnel reuse by PID file
        'use_pid_file' => env('TUNNEL_REUSE_PID_FILE', true),

        // Enable tunnel discovery by port scan (using lsof/netstat)
        'use_port_scan' => env('TUNNEL_REUSE_PORT_SCAN', true),

        // PID file storage directory (default: system temp directory)
        'pid_directory' => env('TUNNEL_PID_DIRECTORY', sys_get_temp_dir() . '/laravel-autossh-tunnel'),
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
