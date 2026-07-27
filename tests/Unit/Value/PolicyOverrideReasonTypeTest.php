<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\PolicyOverrideReasonType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyOverrideReasonTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, PolicyOverrideReasonType}>
     */
    public static function registeredTokens(): iterable
    {
        yield 'forwarded' => ['forwarded', PolicyOverrideReasonType::Forwarded];
        yield 'sampled out' => ['sampled_out', PolicyOverrideReasonType::SampledOut];
        yield 'trusted forwarder' => ['trusted_forwarder', PolicyOverrideReasonType::TrustedForwarder];
        yield 'mailing list' => ['mailing_list', PolicyOverrideReasonType::MailingList];
        yield 'local policy' => ['local_policy', PolicyOverrideReasonType::LocalPolicy];
        yield 'other' => ['other', PolicyOverrideReasonType::Other];
    }

    #[DataProvider('registeredTokens')]
    public function testEveryTokenRegisteredByTheRfcIsRecognised(string $token, PolicyOverrideReasonType $expected): void
    {
        self::assertSame($expected, PolicyOverrideReasonType::fromReportValue($token));
    }

    public function testAnUnregisteredTokenIsRecordedAsOtherRatherThanRejected(): void
    {
        // Reports come from third parties. A vendor inventing its own token
        // must not cost us an otherwise-valid report, and RFC 7489 §6.7.3
        // designates `other` as the bucket for exactly this.
        self::assertSame(PolicyOverrideReasonType::Other, PolicyOverrideReasonType::fromReportValue('vendor_specific_thing'));
        self::assertSame(PolicyOverrideReasonType::Other, PolicyOverrideReasonType::fromReportValue(''));
    }

    public function testTokensAreMatchedRegardlessOfCasingOrPadding(): void
    {
        // Pretty-printed XML pads element text, and reporters are inconsistent
        // about case; neither should downgrade a known reason to `other`.
        self::assertSame(PolicyOverrideReasonType::LocalPolicy, PolicyOverrideReasonType::fromReportValue('  Local_Policy '));
        self::assertSame(PolicyOverrideReasonType::Forwarded, PolicyOverrideReasonType::fromReportValue("\n FORWARDED\n"));
    }
}
