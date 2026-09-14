<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Settings\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Minimal in-memory DatabasePort stand-in — enough to prove which row a
 * `SettingsRepository::fetch()` sees, without a real connection.
 */
final class FakeDatabasePort implements DatabasePort
{
    /** @var array<string, mixed>|null */
    private ?array $row;

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];

    /** @param array<string, mixed>|null $row Row returned by every queryOne() call, or null for "no row". */
    public function __construct(?array $row = null)
    {
        $this->row = $row;
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->row === null ? [] : [$this->row];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->row;
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        return 0;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return '0';
    }

    public function beginTransaction(): void {}

    public function commit(): void {}

    public function rollback(): void {}

    public function inTransaction(): bool
    {
        return false;
    }
}
