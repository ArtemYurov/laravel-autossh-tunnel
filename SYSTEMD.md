# Running SSH Tunnel as systemd Service

This guide explains how to run the SSH tunnel as a persistent system service on Linux using systemd.

## Prerequisites

- Linux system with systemd
- SSH tunnel configured and tested manually
- Root/sudo access

## Create systemd Service File

Create a new service file:

```bash
sudo nano /etc/systemd/system/ssh-tunnel.service
```

Add the following configuration:

```ini
[Unit]
Description=SSH Tunnel for Database
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/your-app
ExecStart=/usr/bin/php artisan tunnel:start remote --detach
ExecStop=/usr/bin/php artisan tunnel:stop remote
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# Security hardening (optional)
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

### Configuration Options

**[Unit] Section:**
- `Description` - Human-readable description of the service
- `After` - Start after network is available
- `Wants` - Prefer to start after network is fully online

**[Service] Section:**
- `Type=simple` - Service starts and runs in foreground
- `User/Group` - Run as specific user (change to your web server user)
- `WorkingDirectory` - Path to your Laravel application
- `ExecStart` - Command to start the tunnel
- `ExecStop` - Command to stop the tunnel
- `Restart=always` - Auto-restart on failure
- `RestartSec=10` - Wait 10 seconds before restart

**[Install] Section:**
- `WantedBy=multi-user.target` - Enable on system boot

## Enable and Start Service

Reload systemd to recognize the new service:

```bash
sudo systemctl daemon-reload
```

Enable the service to start on boot:

```bash
sudo systemctl enable ssh-tunnel
```

Start the service:

```bash
sudo systemctl start ssh-tunnel
```

## Service Management

### Check Status

```bash
sudo systemctl status ssh-tunnel
```

Example output:
```
● ssh-tunnel.service - SSH Tunnel for Database
     Loaded: loaded (/etc/systemd/system/ssh-tunnel.service; enabled; vendor preset: enabled)
     Active: active (running) since Mon 2024-01-15 10:30:00 UTC; 2h 15min ago
   Main PID: 12345 (php)
      Tasks: 2 (limit: 4915)
     Memory: 15.2M
        CPU: 1.234s
     CGroup: /system.slice/ssh-tunnel.service
             └─12345 /usr/bin/php artisan tunnel:start remote --detach
```

### Stop Service

```bash
sudo systemctl stop ssh-tunnel
```

### Restart Service

```bash
sudo systemctl restart ssh-tunnel
```

### Disable Auto-Start

```bash
sudo systemctl disable ssh-tunnel
```

## View Logs

View recent logs:

```bash
sudo journalctl -u ssh-tunnel
```

Follow logs in real-time:

```bash
sudo journalctl -u ssh-tunnel -f
```

View logs from last boot:

```bash
sudo journalctl -u ssh-tunnel -b
```

View logs with timestamps:

```bash
sudo journalctl -u ssh-tunnel --since "1 hour ago"
```

## Multiple Tunnels

If you need multiple tunnels, create separate service files:

### Tunnel 1: PostgreSQL

`/etc/systemd/system/ssh-tunnel-pgsql.service`:
```ini
[Unit]
Description=SSH Tunnel for PostgreSQL Database
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php artisan tunnel:start pgsql --detach
ExecStop=/usr/bin/php artisan tunnel:stop pgsql
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### Tunnel 2: MySQL

`/etc/systemd/system/ssh-tunnel-mysql.service`:
```ini
[Unit]
Description=SSH Tunnel for MySQL Database
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php artisan tunnel:start mysql --detach
ExecStop=/usr/bin/php artisan tunnel:stop mysql
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable both:
```bash
sudo systemctl daemon-reload
sudo systemctl enable ssh-tunnel-pgsql ssh-tunnel-mysql
sudo systemctl start ssh-tunnel-pgsql ssh-tunnel-mysql
```

## Troubleshooting

### Service Fails to Start

1. Check service status for error messages:
   ```bash
   sudo systemctl status ssh-tunnel
   ```

2. Check detailed logs:
   ```bash
   sudo journalctl -u ssh-tunnel -n 50 --no-pager
   ```

3. Verify PHP path:
   ```bash
   which php
   ```

4. Test command manually:
   ```bash
   cd /var/www/your-app
   sudo -u www-data php artisan tunnel:start remote --detach
   ```

### Permission Issues

Ensure the service user has access to:
- Laravel application directory
- SSH keys (if used)
- Storage directory for PID files

```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/your-app/storage
sudo chmod -R 755 /var/www/your-app/storage
```

### SSH Key Issues

If using SSH keys, ensure they're accessible:

```bash
# Copy SSH key to service user's home
sudo mkdir -p /var/www/.ssh
sudo cp ~/.ssh/id_rsa /var/www/.ssh/
sudo chown -R www-data:www-data /var/www/.ssh
sudo chmod 700 /var/www/.ssh
sudo chmod 600 /var/www/.ssh/id_rsa
```

Update `.env`:
```env
TUNNEL_KEY=/var/www/.ssh/id_rsa
```

## Docker Considerations

If running in Docker, consider using Docker's restart policies instead of systemd:

```yaml
# docker-compose.yml
services:
  tunnel:
    build: .
    command: php artisan tunnel:start remote --detach
    restart: unless-stopped
    volumes:
      - .:/var/www/app
```

## Security Best Practices

1. **Use dedicated SSH keys** for tunnels with limited permissions
2. **Run as non-root user** (www-data, app user, etc.)
3. **Enable firewall** to restrict local port access
4. **Monitor logs** regularly for suspicious activity
5. **Use StrictHostKeyChecking** for known hosts
6. **Rotate SSH keys** periodically

## Alternative: Supervisor

If systemd is not available, you can use Supervisor:

```ini
[program:ssh-tunnel]
command=php /var/www/app/artisan tunnel:start remote --detach
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ssh-tunnel.log
```

Start with:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ssh-tunnel
```
