# Laravel AutoSSH Tunnel

Modern SSH Tunnel Manager for Laravel with autossh support and automatic lifecycle management.

## Features

- 🚀 Automatic tunnel lifecycle management via callback pattern
- 🔄 AutoSSH support for automatic reconnection
- 🛡️ Comprehensive error handling with detailed messages
- 🔌 Port availability checking
- 🗄️ Laravel Database connections integration
- ⚙️ Flexible configuration (env, config files or direct parameters)
- ✅ Configuration validation
- 📝 Detailed logging
- 🎯 Multiple simultaneous tunnels support

## Installation

```bash
composer require artemyurov/laravel-autossh-tunnel
```

### Publish Configuration

```bash
# Publish config and .env example
php artisan vendor:publish --tag=tunnel

# Or publish separately
php artisan vendor:publish --tag=tunnel-config  # config/tunnel.php only
php artisan vendor:publish --tag=tunnel-env     # .env.example.tunnel only
```

After publishing, copy the tunnel environment variables to your `.env`:

```bash
cat .env.example.tunnel >> .env
# Then edit .env with your actual credentials
```

## Configuration

### Environment Variables

The configuration uses a clear logical order:
1. **SSH Connection** - How to connect to the SSH server
2. **Remote/Local** - What to forward and where
3. **SSH Options** - Connection behavior settings

```env
# Default tunnel connection
TUNNEL_CONNECTION=remote_db
TUNNEL_DEBUG=false
TUNNEL_AUTOSSH_ENABLED=true

# SSH Connection (how to connect)
TUNNEL_SSH_USER=your_ssh_user
TUNNEL_SSH_HOST=your_server.com
TUNNEL_SSH_PORT=22
TUNNEL_SSH_KEY=/path/to/ssh/key

# Remote Target (what to forward on SSH server)
TUNNEL_REMOTE_HOST=localhost
TUNNEL_REMOTE_PORT=5432

# Local Bind (where to bind locally)
TUNNEL_LOCAL_PORT=15432

# SSH Options
TUNNEL_SSH_STRICT_HOST_KEY_CHECKING=false
TUNNEL_SSH_SERVER_ALIVE_INTERVAL=60
TUNNEL_SSH_SERVER_ALIVE_COUNT_MAX=3
TUNNEL_SSH_EXIT_ON_FORWARD_FAILURE=true
TUNNEL_SSH_TCP_KEEP_ALIVE=true
TUNNEL_SSH_CONNECT_TIMEOUT=10

# Database connection using tunnel
TUNNEL_DB_HOST=localhost
TUNNEL_DB_PORT="${TUNNEL_LOCAL_PORT}"
TUNNEL_DB_DATABASE=database_name
TUNNEL_DB_USERNAME=db_user
TUNNEL_DB_PASSWORD=db_password
```

### Configuration File

After publishing the config, edit `config/tunnel.php`:

```php
return [
    'default' => 'remote_db',

    'connections' => [
        'remote_db' => [
            'type' => 'forward',
            // SSH Connection
            'user' => 'your_user',
            'host' => 'production.example.com',
            'port' => 22,
            'identity_file' => '/path/to/ssh/key',
            // Remote Target
            'remote_host' => 'localhost',
            'remote_port' => 5432,
            // Local Bind
            'local_port' => 15432,
            // SSH Options (all optional, defaults shown)
            'ssh_options' => [
                'StrictHostKeyChecking' => false,
                'ServerAliveInterval' => 60,
                'ServerAliveCountMax' => 3,
                'ExitOnForwardFailure' => true,
                'TCPKeepAlive' => true,
                'ConnectTimeout' => 10,
            ],
        ],

        'local_webhooks' => [
            'type' => 'reverse',
            'user' => 'your_user',
            'host' => 'public-server.com',
            'port' => 22,
            'remote_host' => 'localhost',  // Or 0.0.0.0 for public access
            'remote_port' => 8080,
            'local_port' => 8000,
            'ssh_options' => [
                'StrictHostKeyChecking' => false,
                'ServerAliveInterval' => 60,
                'ServerAliveCountMax' => 3,
                'ExitOnForwardFailure' => true,
                'TCPKeepAlive' => true,
                'ConnectTimeout' => 10,
            ],
        ],
    ],
];
```

## Tunnel Types

The package supports two types of SSH tunnels:

### Forward Tunnel (`-L`) - Access Remote Services

Forward tunnels allow you to access remote services from your local machine.

```
┌─────────────┐         SSH Tunnel          ┌─────────────┐
│   Local     │ ──────────────────────────> │   Remote    │
│  Machine    │  localhost:15432            │   Server    │
│             │                             │             │
│             │                             │ PostgreSQL  │
│             │  <───────────────────────── │ :5432       │
└─────────────┘                             └─────────────┘
```

**SSH Command:**
```
ssh -L 15432:localhost:5432 user@server.com
        │      │        │
        │      │        └─ remote_port (порт на удалённом сервере)
        │      └────────── remote_host (хост на удалённом сервере)
        └───────────────── local_port (порт на локальной машине)
```

**Use Cases:**
- Access production/staging databases for debugging
- Connect to internal APIs not exposed to the internet
- Access remote services (Redis, Elasticsearch, etc.)
- Secure connection to remote development environments

**Example:**
```php
use ArtemYurov\Autossh\Facades\Tunnel;

// Access remote database
Tunnel::connection('remote_db')->execute(function() {
    // Connect to remote PostgreSQL via localhost:15432
    $users = DB::connection('pgsql_remote')->table('users')->get();
});
```

### Reverse Tunnel (`-R`) - Expose Local Services

Reverse tunnels expose your local application to a remote server, making it accessible from the internet.

```
┌─────────────┐         SSH Tunnel          ┌─────────────┐
│   Local     │ <────────────────────────── │   Remote    │
│  Machine    │                             │   Server    │
│             │                             │             │
│ Laravel     │                             │ Public IP   │
│ :8000       │  ───────────────────────>   │ :8080       │
└─────────────┘                             └─────────────┘
                                                    │
                                             Webhooks from:
                                             • Stripe
                                             • GitHub
                                             • Telegram
                                             • PayPal
```

**SSH Command:**
```
ssh -R 8080:localhost:8000 user@server.com
        │      │        │
        │      │        └─ local_port (порт локальной машины)
        │      └────────── remote_host (bind адрес на удалённом сервере)
        └───────────────── remote_port (порт на удалённом сервере)
```

**Important:** `remote_host` is the bind address **on the remote server**:
- `localhost` - tunnel accessible only locally on remote server (127.0.0.1:8080)
- `0.0.0.0` - tunnel publicly accessible (your-server.com:8080 from internet)

**Use Cases:**
- Test webhooks locally (Stripe, PayPal, GitHub, Telegram)
- Demo local application to clients without deployment
- Temporary public access to development environment
- Receive callbacks from external services

**Example:**
```php
use ArtemYurov\Autossh\Facades\Tunnel;

// Expose local Laravel app for webhook testing
Tunnel::connection('local_webhooks')->execute(function() {
    $this->info('Local app is now accessible at http://your-server.com:8080');
    $this->info('Configure webhooks to point to this URL');

    // Keep tunnel open while testing
    sleep(3600); // 1 hour
});
```

## Usage

### Callback Pattern (Recommended)

Automatic tunnel closure after execution:

```php
use ArtemYurov\Autossh\Facades\Tunnel;

// Using configuration from config/tunnel.php
Tunnel::connection('remote_db')->execute(function() {
    // Tunnel is active, you can work with remote service
    // Tunnel will automatically close after execution
});
```

### With Laravel Database Integration

```php
use ArtemYurov\Autossh\Facades\Tunnel;
use Illuminate\Support\Facades\DB;

Tunnel::connection('remote_db')
    ->withDatabaseConnection('pgsql_remote', [
        'driver' => 'pgsql',
        'database' => env('REMOTE_DB_DATABASE'),
        'username' => env('REMOTE_DB_USERNAME'),
        'password' => env('REMOTE_DB_PASSWORD'),
    ])
    ->execute(function() {
        // Now you can use the connection
        $users = DB::connection('pgsql_remote')->table('users')->get();

        // Tunnel will automatically close after execution
    });
```

### Manual Management

```php
use ArtemYurov\Autossh\Facades\Tunnel;

$connection = Tunnel::connection('remote_db')->start();

try {
    // Work with tunnel
    $pid = $connection->getPid();
    $isRunning = $connection->isRunning();
} finally {
    // Must close the tunnel
    $connection->stop();
}
```

### Multiple Tunnels Simultaneously

```php
use ArtemYurov\Autossh\Tunnel;

// PostgreSQL tunnel
$pgTunnel = Tunnel::connection('pgsql')->start();

// MySQL tunnel
$mysqlTunnel = Tunnel::connection('mysql')->start();

try {
    // Work with both tunnels
} finally {
    $pgTunnel->stop();
    $mysqlTunnel->stop();
}
```

### Using in Artisan Commands

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use ArtemYurov\Autossh\Facades\Tunnel;
use Illuminate\Support\Facades\DB;

class SyncDatabase extends Command
{
    protected $signature = 'db:sync';

    public function handle(): int
    {
        return Tunnel::connection('remote_db')
            ->withDatabaseConnection('remote_db', [
                'driver' => 'pgsql',
                'database' => env('REMOTE_DB_DATABASE'),
                'username' => env('REMOTE_DB_USERNAME'),
                'password' => env('REMOTE_DB_PASSWORD'),
            ])
            ->execute(function() {
                $this->info('Syncing database...');

                // Your sync logic
                $tables = DB::connection('remote_db')
                    ->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

                $this->info('Found tables: ' . count($tables));

                return Command::SUCCESS;
            });
    }
}
```

## Long-Running Tunnels

For persistent tunnels that stay active (similar to ngrok), use the Artisan commands:

### Start with Live Monitoring

Start a tunnel with real-time status updates (like ngrok):

```bash
php artisan tunnel:start
# or specify connection
php artisan tunnel:start remote_db
```

This will show a live dashboard with tunnel information:
```
╔════════════════════════════════════════════════════════════════╗
║                     SSH Tunnel Monitor                         ║
╠════════════════════════════════════════════════════════════════╣
║ Connection: remote_db                                          ║
║ Local Port: 15432                                              ║
║ Remote:     localhost:5432                                     ║
║ SSH:        user@example.com                                   ║
║ PID:        12345                                              ║
╠════════════════════════════════════════════════════════════════╣
║ Press Ctrl+C to stop the tunnel                                ║
╚════════════════════════════════════════════════════════════════╝

Status: ● ACTIVE | Uptime: 2m 34s | PID: 12345
```

Press `Ctrl+C` to gracefully stop the tunnel.

### Start in Background (Daemon Mode)

Run tunnel in background without monitoring (detached daemon mode):

```bash
php artisan tunnel:start --detach
# or
php artisan tunnel:start remote_db --detach
```

### Check Tunnel Status

View status of running tunnels:

```bash
# Check specific tunnel
php artisan tunnel:status
php artisan tunnel:status remote_db

# Check all running tunnels
php artisan tunnel:status --all
```

### Stop Tunnel

Stop a running tunnel:

```bash
php artisan tunnel:stop
php artisan tunnel:stop remote_db

# Stop all tunnels
php artisan tunnel:stop --all
```

### Run as System Service

For production environments, you can run the tunnel as a persistent systemd service.

**📖 See detailed guide:** [SYSTEMD.md](SYSTEMD.md)

Quick example:
```bash
# Create service file
sudo nano /etc/systemd/system/ssh-tunnel.service

# Enable and start
sudo systemctl enable ssh-tunnel
sudo systemctl start ssh-tunnel
```

### Related Resources

- **[Running as systemd Service](SYSTEMD.md)** - Complete guide for production deployments
- [Self-hosted ngrok alternative](https://jerrington.me/posts/2019-01-29-self-hosted-ngrok.html) - Building your own tunnel infrastructure

## AutoSSH

The package automatically detects `autossh` availability and uses it instead of regular `ssh` to provide automatic reconnection on connection loss.

### Installing autossh

**macOS:**
```bash
brew install autossh
```

**Ubuntu/Debian:**
```bash
apt-get install autossh
```

The package uses `command -v autossh` to detect autossh automatically. No additional configuration needed.

### Disable AutoSSH

If you want to use regular SSH even when autossh is available:

```env
TUNNEL_AUTOSSH_ENABLED=false
```

## Error Handling

```php
use ArtemYurov\Autossh\Facades\Tunnel;
use ArtemYurov\Autossh\Exceptions\TunnelConnectionException;
use ArtemYurov\Autossh\Exceptions\TunnelConfigException;

try {
    Tunnel::connection('remote_db')->execute(function() {
        // Your code
    });
} catch (TunnelConfigException $e) {
    // Configuration error (invalid port, missing key, etc.)
    Log::error('Tunnel configuration error: ' . $e->getMessage());
} catch (TunnelConnectionException $e) {
    // Connection error (port occupied, SSH failed, etc.)
    Log::error('Tunnel connection error: ' . $e->getMessage());
}
```

## API Reference

### Artisan Commands

- `tunnel:start {connection?} {--detach}` - Start tunnel with live monitoring (or in background with --detach)
- `tunnel:stop {connection?} {--all}` - Stop tunnel (or all tunnels with --all)
- `tunnel:status {connection?} {--all}` - Show tunnel status (or all tunnels with --all)

### Tunnel

#### Static Methods

- `Tunnel::connection(?string $name = null): Tunnel` - Create from config/tunnel.php
- `Tunnel::fromConfig(TunnelConfig $config): Tunnel` - Create from config object

#### Instance Methods

- `withDatabaseConnection(string $name, array $config): self` - Register Laravel DB connection
- `start(): TunnelConnection` - Start tunnel
- `execute(callable $callback): mixed` - Execute callback with automatic tunnel management
- `getConfig(): TunnelConfig` - Get configuration
- `getConnection(): ?TunnelConnection` - Get active connection

### TunnelConnection

- `isRunning(): bool` - Check if tunnel is running
- `getPid(): ?int` - Get process PID
- `stop(): void` - Stop tunnel
- `verifyConnection(): bool` - Verify tunnel availability

### TunnelManager

- `saveTunnel(string $name, TunnelConnection $connection): void` - Save tunnel info to storage
- `getTunnelInfo(string $name): ?array` - Get tunnel information
- `stopTunnel(string $name): bool` - Stop tunnel by name
- `getAllTunnels(): array` - Get all running tunnels
- `getUptime(array $info): int` - Get tunnel uptime in seconds
- `formatUptime(int $seconds): string` - Format uptime as human-readable string

## Logging

The package uses standard Laravel Log facade. For detailed logging:

```env
TUNNEL_DEBUG=true
```

Or in `config/tunnel.php`:

```php
'debug' => true,
```

## Requirements

- PHP ^8.2
- Laravel ^10.0|^11.0|^12.0
- SSH client installed on the system
- AutoSSH (optional, for auto-reconnection)

## License

MIT License

## Author

Artem Yurov (artem@yurov.org)
