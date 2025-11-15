<?php

namespace ArtemYurov\Autossh\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ArtemYurov\Autossh\Tunnel connection(string|null $name = null)
 * @method static \ArtemYurov\Autossh\Tunnel fromConfig(\ArtemYurov\Autossh\DTO\TunnelConfig $config)
 * @method static \ArtemYurov\Autossh\Tunnel withDatabaseConnection(string $connectionName, array $config)
 * @method static \ArtemYurov\Autossh\TunnelConnection start()
 * @method static mixed execute(callable $callback)
 * @method static \ArtemYurov\Autossh\DTO\TunnelConfig getConfig()
 * @method static \ArtemYurov\Autossh\TunnelConnection|null getConnection()
 *
 * @see \ArtemYurov\Autossh\Tunnel
 */
class Tunnel extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'tunnel';
    }
}
