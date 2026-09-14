# Settings plugin

Per-tenant settings (`tenant.settings`). Reads/writes the central
`tenant_settings_*` tables, keyed by `tenant_id`, exposing
`SettingsServiceContract` and a JSON API under `/api/settings/*`.

The acting tenant always comes from the authenticated `Identity` (the signed
`tnt` claim) — never from client input. An unscoped (central) token is rejected
with `403`.

## Read / write semantics

- **`GET /api/settings/{section}`** returns the tenant's stored row, or a fully
  populated DTO of hard-coded defaults (`DTO::defaults()`) when none exists —
  callers never see nulls for defaulted columns.
- **`PUT /api/settings/{section}`** is a **partial update**: the payload is
  validated, then merged over the tenant's *current* stored settings, so an
  omitted field keeps its existing value (it is never reset to a default). Input
  is validated via `Plugins\Validation\Validator` → `422` with field errors.
- **Writes are authorized**: the caller needs the `settings:manage` permission or
  an `admin`/`super` role, else `ServiceException` (unauthorized). Reads are open
  to any authenticated tenant member.

## Sections

| Section          | Table                              | DTO                         |
|------------------|------------------------------------|-----------------------------|
| `company`        | `tenant_settings_company`          | `CompanySettingsDTO`        |
| `contact`        | `tenant_settings_contact`          | `ContactSettingsDTO`        |
| `email`          | `tenant_settings_email`            | `EmailSettingsDTO`          |
| `email_providers`| `tenant_settings_email_providers`  | `EmailProviderSettingsDTO`  |
| `system`         | `tenant_settings_system`           | `SystemSettingsDTO`         |

`Domain/ValueObjects/SettingsSection` is the single source of truth for the
section→table mapping; no caller-supplied string is ever interpolated into SQL.

## Company logo

The company logo is a stored blob, not a path the client controls. Two routes
(both `requires: ["storage.local"]`) manage it via `StoragePort`:

- **`POST /api/settings/company/logo`** — multipart field `company_logo`.
  Validates the extension (`png, jpg, jpeg, gif, webp, svg, ico`), stores the
  blob under `tenants/{tenantId}/branding/` with a random name, persists its path
  onto `company_logo`, deletes the previous blob, and returns the settings plus a
  signed `logo_url`. A failed authorization/persist deletes the just-stored blob
  (no orphans).
- **`DELETE /api/settings/company/logo`** — clears `company_logo` and deletes the
  stored blob.

Unlike the legacy controller, nothing is written to `public_path()` and there is
no favicon-set side effect — a single blob per tenant, served via a signed URL.

## System read path — `TenantSettingsReaderContract`

`SettingsServiceContract::email()` / `::contact()` are the AUTHENTICATED
CRUD path: they require `Identity` and call `assertCanManage()` (the email
section is permission-gated), and their repository reads through the
per-request `DatabasePort` — which Tenancy's `TenantContextStage` rebinds to
the TENANT database on a tenant-scoped host.

Server-side delivery code has neither of those things available. Sending the
Contact-page mail (and the admin's reply) needs the tenant's SMTP + contact
settings from an **anonymous** request, and `tenant_settings_*` are CENTRAL
control-plane tables — reading them through a possibly-rebound `DatabasePort`
would silently hit the tenant database instead.

`Plugins\Settings\API\Contracts\TenantSettingsReaderContract` exists for
exactly that case:

```php
interface TenantSettingsReaderContract
{
    /** Null when the tenant has no `tenant_settings_email` row yet. */
    public function email(string $tenantId): ?EmailSettingsDTO;

    /** Null when the tenant has no `tenant_settings_contact` row yet. */
    public function contact(string $tenantId): ?ContactSettingsDTO;
}
```

- **No `Identity`, no permission check.** Callable from an anonymous request,
  a queue worker, or a CLI job — anything with no authenticated caller.
- **Always reads the CENTRAL connection**, via
  `Plugins\Database\API\Contracts\DatabaseConnectionManagerContract::default()`
  — never the per-request `DatabasePort`. This is the same pattern Tenancy's
  own control-plane code uses to reach central tables regardless of what the
  current request has rebound `DatabasePort` to.
- **Returns `null`**, not a defaulted DTO, when the tenant has no row —
  unlike the CRUD path's `company()`/`system()`/etc., which return
  `DTO::defaults()` so an authenticated caller never sees a null. A mail
  sender needs to tell "not configured" apart from "configured with empty
  values" and falls back to `.env` in the former case.
- **Never expose this over HTTP or echo its result to an unauthenticated
  caller.** `EmailSettingsDTO` carries the SMTP password in the clear (see
  the security note below) — `TenantSettingsReaderContract` has no built-in
  authorization to stop that.

```php
final class ContactMailer
{
    public function __construct(
        private readonly TenantSettingsReaderContract $settings,
    ) {}

    public function send(string $tenantId, ContactFormDTO $form): void
    {
        $email   = $this->settings->email($tenantId);
        $contact = $this->settings->contact($tenantId);

        $host = $email?->smtpHost ?? env('MAIL_HOST');
        // … build the transport from $email, falling back to .env when null.
    }
}
```

A route needs no new domain to consume this contract: it is exposed by the
same `tenant.settings` module `SettingsServiceContract` already is, so
`"requires": ["tenant.settings"]` is sufficient.

### Known gap — secrets are stored in plaintext

`smtp_password` (`tenant_settings_email`) and the third-party provider keys
(`sendgrid_api_key`, `mailgun_api_key`, `postmark_server_token`,
`aws_secret_access_key` — `tenant_settings_email_providers`) are stored as
plain `varchar` columns and round-tripped verbatim by `toRow()`/`fromRow()`.
`EmailSettingsDTO::toArray()` / `EmailProviderSettingsDTO::toArray()` mask
these for the HTTP GET response (a `********ab12`-style hint), but that
masking is a response-shaping concern only — the underlying storage is not
encrypted, and `TenantSettingsReaderContract` (this system read path) returns
the UNMASKED value on purpose, since it feeds a real SMTP/API connection.
Encrypting these columns at rest is a real gap; it is out of scope for this
change and is not addressed here.

## Migrations

The schema lives in `database/migrations/` and is owned by this plugin. These are
central control-plane tables (`char(31)` `tenant_id` PK) with a foreign key to the
Tenancy `tenants` table, so they run on the **central** connection and require
Tenancy's `tenants` table to exist first (earlier migration timestamps guarantee
that ordering).

This framework does **not** declare migration directories in `module.json`.
Register this plugin's path in the project's `config/let-migrate.php` `paths[]`:

```php
'paths' => [
    $root . '/database/migrations',
    $root . '/plugins/Tenancy/database/migrations',  // tenants (FK target) — first
    $root . '/plugins/Settings/database/migrations',
],
```

Then `php cli/run.php migrate:run` applies them in timestamp order across all paths.

## Wiring

Register the provider in the project bootstrap, then opt routes in on demand:

```php
->withModules([ /* … */ \Plugins\Settings\Provider::class ])
```

A route uses it via `"requires": ["tenant.settings"]`.
