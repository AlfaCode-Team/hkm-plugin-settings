<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Settings\API\DTOs\EmailProviderSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;

/**
 * Regression cover for the response half of SET-01: provider credentials and
 * the SMTP password were echoed verbatim by the settings GET endpoint.
 */
#[CoversClass(EmailProviderSettingsDTO::class)]
#[CoversClass(EmailSettingsDTO::class)]
final class SecretExposureTest extends TestCase
{
    private const SENDGRID = 'SG.abcdefghijklmnop.qrstuvwxyz123456';

    private function providers(): EmailProviderSettingsDTO
    {
        return EmailProviderSettingsDTO::fromRow([
            'tenant_id'             => 't1',
            'sendgrid_api_key'      => self::SENDGRID,
            'mailgun_api_key'       => 'key-0123456789abcdef',
            'postmark_server_token' => 'pm-0123456789abcdef',
            'aws_secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCY',
            'aws_access_key_id'     => 'AKIAIOSFODNN7EXAMPLE',
        ]);
    }

    public function test_api_responses_do_not_contain_provider_secrets(): void
    {
        $json = json_encode($this->providers()->toArray());

        self::assertStringNotContainsString(self::SENDGRID, $json);
        self::assertStringNotContainsString('key-0123456789abcdef', $json);
        self::assertStringNotContainsString('pm-0123456789abcdef', $json);
        self::assertStringNotContainsString('wJalrXUtnFEMI/K7MDENG/bPxRfiCY', $json);
    }

    public function test_a_masked_value_still_shows_that_a_key_is_configured(): void
    {
        $masked = $this->providers()->toArray()['sendgrid_api_key'];

        self::assertNotNull($masked);
        self::assertStringStartsWith('*', (string) $masked);
        // Last 4 kept so an operator can recognise which key is in place.
        self::assertStringEndsWith(substr(self::SENDGRID, -4), (string) $masked);
    }

    public function test_persistence_still_round_trips_the_real_value(): void
    {
        // toRow() is the storage path and must NOT be masked, or saving the
        // settings would overwrite the real key with asterisks.
        self::assertSame(self::SENDGRID, $this->providers()->toRow()['sendgrid_api_key']);
    }

    public function test_an_absent_secret_stays_absent_rather_than_becoming_stars(): void
    {
        $row = EmailProviderSettingsDTO::fromRow(['tenant_id' => 't1'])->toArray();

        self::assertNotSame('********', $row['sendgrid_api_key'] ?? null);
    }

    public function test_smtp_password_is_masked(): void
    {
        $json = json_encode(
            EmailSettingsDTO::fromRow(['tenant_id' => 't1', 'smtp_password' => 'hunter2hunter2'])->toArray(),
        );

        self::assertStringNotContainsString('hunter2hunter2', $json);
    }
}
