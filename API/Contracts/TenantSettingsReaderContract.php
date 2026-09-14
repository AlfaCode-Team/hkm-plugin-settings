<?php

declare(strict_types=1);

namespace Plugins\Settings\API\Contracts;

use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;

/**
 * System read path for per-tenant settings, for SERVER-SIDE delivery code that
 * has no authenticated Identity to check — e.g. sending the Contact-page mail
 * (and its admin reply) through the tenant's own SMTP/contact settings, or a
 * queue worker/CLI job with no HTTP request in front of it.
 *
 * This is deliberately NOT {@see SettingsServiceContract}:
 *
 *   - `SettingsServiceContract::email()` / `::contact()` call
 *     `assertCanManage()`, which requires a logged-in admin (`settings:manage`
 *     or an `admin`/`super` role). An anonymous Contact-form submission has no
 *     Identity at all, so that call would always throw.
 *   - `SettingsServiceContract`'s repository reads through the PER-REQUEST
 *     `DatabasePort` binding, which Tenancy's `TenantContextStage` rebinds to
 *     the TENANT database on a tenant-scoped host. The `tenant_settings_*`
 *     tables are CENTRAL control-plane tables — on a country/tenant host that
 *     read would silently run against the wrong database.
 *
 * `TenantSettingsReaderService` fixes both: no authorization check, and it
 * always builds its repository against the CENTRAL connection via
 * {@see \Plugins\Database\API\Contracts\DatabaseConnectionManagerContract::default()},
 * independent of whatever `DatabasePort` the current request happens to have
 * bound.
 *
 * Because there is no permission check, a caller of this contract must NEVER
 * expose it over HTTP or echo its result back to an unauthenticated caller —
 * `EmailSettingsDTO` carries the SMTP password (and, via the sibling
 * `email_providers` section, third-party API keys) in the clear.
 */
interface TenantSettingsReaderContract
{
    /** Null when the tenant has no `tenant_settings_email` row yet. */
    public function email(string $tenantId): ?EmailSettingsDTO;

    /** Null when the tenant has no `tenant_settings_contact` row yet. */
    public function contact(string $tenantId): ?ContactSettingsDTO;
}
