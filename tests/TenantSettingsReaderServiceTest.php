<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;
use Plugins\Settings\Application\Services\TenantSettingsReaderService;
use Tests\Unit\Plugins\Settings\Support\FakeConnectionManager;
use Tests\Unit\Plugins\Settings\Support\FakeDatabasePort;

/**
 * TenantSettingsReaderService is the SYSTEM read path used by server-side mail
 * delivery: no Identity, no permission check, and it must read through the
 * CENTRAL connection regardless of any per-request DatabasePort rebind (see
 * TenantSettingsReaderContract). These tests build the service directly from a
 * {@see FakeConnectionManager} — there is no request, no Identity, and no
 * SecurityGateway anywhere in the setup, which is itself the "no identity
 * required" assertion.
 */
#[CoversClass(TenantSettingsReaderService::class)]
final class TenantSettingsReaderServiceTest extends TestCase
{
    public function test_email_returns_null_when_the_tenant_has_no_row(): void
    {
        $connections = new FakeConnectionManager(new FakeDatabasePort(row: null));
        $service     = new TenantSettingsReaderService($connections);

        self::assertNull($service->email('tenant-1'));
    }

    public function test_contact_returns_null_when_the_tenant_has_no_row(): void
    {
        $connections = new FakeConnectionManager(new FakeDatabasePort(row: null));
        $service     = new TenantSettingsReaderService($connections);

        self::assertNull($service->contact('tenant-1'));
    }

    public function test_email_hydrates_the_dto_from_the_stored_row(): void
    {
        $db = new FakeDatabasePort(row: [
            'tenant_id'              => 'tenant-1',
            'email_provider'         => 'smtp',
            'smtp_host'              => 'smtp.example.com',
            'smtp_port'              => 2525,
            'smtp_username'          => 'mailer@example.com',
            'smtp_password'          => 'super-secret',
            'smtp_encryption'        => 'ssl',
            'sender_name'            => 'Example Support',
            'sender_email'           => 'support@example.com',
            'email_reply_to'         => 'replies@example.com',
            'email_reply_to_enabled' => 1,
            'email_test_mode'        => 0,
        ]);
        $connections = new FakeConnectionManager($db);
        $service     = new TenantSettingsReaderService($connections);

        $dto = $service->email('tenant-1');

        self::assertInstanceOf(EmailSettingsDTO::class, $dto);
        self::assertSame('tenant-1', $dto->tenantId);
        self::assertSame('smtp', $dto->provider);
        self::assertSame('smtp.example.com', $dto->smtpHost);
        self::assertSame(2525, $dto->smtpPort);
        self::assertSame('mailer@example.com', $dto->smtpUsername);
        // Reader returns the RAW row value — this is the unmasked system path,
        // unlike the DTO's own toArray() (used by the HTTP GET endpoint).
        self::assertSame('super-secret', $dto->smtpPassword);
        self::assertSame('ssl', $dto->smtpEncryption);
        self::assertSame('Example Support', $dto->senderName);
        self::assertSame('support@example.com', $dto->senderEmail);
        self::assertSame('replies@example.com', $dto->replyTo);
        self::assertTrue($dto->replyToEnabled);
        self::assertFalse($dto->testMode);

        // Reads through the CENTRAL ("default") connection, never anything else.
        self::assertSame(1, $connections->defaultCalls);
        self::assertStringContainsString('tenant_settings_email', $db->queries[0]['sql']);
        self::assertSame('tenant-1', $db->queries[0]['params']['tid']);
    }

    public function test_contact_hydrates_the_dto_from_the_stored_row(): void
    {
        $db = new FakeDatabasePort(row: [
            'tenant_id'                  => 'tenant-1',
            'contact_form_recipients'    => 'sales@example.com,support@example.com',
            'contact_auto_reply_subject' => 'We got your message',
            'contact_auto_reply_message' => 'Thanks for reaching out — we will reply soon.',
        ]);
        $connections = new FakeConnectionManager($db);
        $service     = new TenantSettingsReaderService($connections);

        $dto = $service->contact('tenant-1');

        self::assertInstanceOf(ContactSettingsDTO::class, $dto);
        self::assertSame('tenant-1', $dto->tenantId);
        self::assertSame('sales@example.com,support@example.com', $dto->formRecipients);
        self::assertSame('We got your message', $dto->autoReplySubject);
        self::assertSame('Thanks for reaching out — we will reply soon.', $dto->autoReplyMessage);

        self::assertSame(1, $connections->defaultCalls);
        self::assertStringContainsString('tenant_settings_contact', $db->queries[0]['sql']);
    }

    public function test_reading_never_touches_a_request_scoped_dependency(): void
    {
        // TenantSettingsReaderService's constructor takes ONLY the connection
        // manager — no Identity, no Request, no ModuleContainer. Reflection
        // pins that shape so a future edit cannot quietly reintroduce one of
        // those and break the "usable with no authenticated caller" contract.
        $ctor = new \ReflectionMethod(TenantSettingsReaderService::class, '__construct');
        $params = $ctor->getParameters();

        self::assertCount(1, $params);
        self::assertSame('connections', $params[0]->getName());
    }
}
