<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Settings\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;

/**
 * Stands in for the Database plugin's real ConnectionManager. Lets a test hand
 * a specific DatabasePort for the "default" (central) connection, independent
 * of whatever the per-request DatabasePort binding happens to be — the whole
 * point of {@see \Plugins\Settings\Application\Services\TenantSettingsReaderService}.
 */
final class FakeConnectionManager implements DatabaseConnectionManagerContract
{
    /** @var array<string, DatabasePort> */
    private array $connections;

    public int $defaultCalls = 0;

    public function __construct(DatabasePort $default)
    {
        $this->connections = ['default' => $default];
    }

    public function connection(string $name = 'default'): DatabasePort
    {
        return $this->connections[$name];
    }

    public function default(): DatabasePort
    {
        $this->defaultCalls++;

        return $this->connection('default');
    }

    public function register(string $name, DatabaseConfigurationContract $config): void
    {
        // Not needed by these tests.
    }

    public function has(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    public function connections(): array
    {
        return array_keys($this->connections);
    }

    public function close(string $name = 'default'): void {}

    public function closeAll(): void {}
}
