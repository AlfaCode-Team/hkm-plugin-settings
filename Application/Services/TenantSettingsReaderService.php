<?php

declare(strict_types=1);

namespace Plugins\Settings\Application\Services;

use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Settings\API\Contracts\TenantSettingsReaderContract;
use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;
use Plugins\Settings\Domain\ValueObjects\SettingsSection;
use Plugins\Settings\Infrastructure\Persistence\SettingsRepository;

/**
 * System read path for `tenant_settings_email` / `tenant_settings_contact` —
 * see {@see TenantSettingsReaderContract} for why this exists and why it is
 * not `SettingsServiceContract`.
 *
 * No Identity, no authorization check, no transaction — this is a read-only
 * lookup for server-side delivery code.
 *
 * ALWAYS reads through the CENTRAL connection: this is the one place in the
 * module allowed to build a {@see SettingsRepository} directly instead of
 * resolving one from the container. The container's `SettingsRepository`
 * binding (used by `SettingsService`) is deliberately wired to the
 * PER-REQUEST `DatabasePort`, which is correct for the authenticated CRUD
 * path but wrong here — Tenancy's `TenantContextStage` rebinds that port to
 * the tenant database, and `tenant_settings_*` only exists centrally. Going
 * through `DatabaseConnectionManagerContract::default()` sidesteps that
 * rebind entirely, the same way Tenancy's own control-plane code does.
 */
final class TenantSettingsReaderService implements TenantSettingsReaderContract
{
    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
    ) {}

    public function email(string $tenantId): ?EmailSettingsDTO
    {
        $settings = $this->repository()->fetch(SettingsSection::Email, $tenantId);

        return $settings === null ? null : EmailSettingsDTO::fromRow($settings->toRawArray());
    }

    public function contact(string $tenantId): ?ContactSettingsDTO
    {
        $settings = $this->repository()->fetch(SettingsSection::Contact, $tenantId);

        return $settings === null ? null : ContactSettingsDTO::fromRow($settings->toRawArray());
    }

    /**
     * Built fresh per call against the connection manager's memoised "default"
     * adapter — {@see \Plugins\Database\Infrastructure\Persistence\ConnectionManager::default()}
     * caches the resolved `DatabasePort`, so this does not open a new
     * connection per call.
     */
    private function repository(): SettingsRepository
    {
        return new SettingsRepository($this->connections->default());
    }
}
