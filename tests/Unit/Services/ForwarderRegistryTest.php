<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ForwarderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ForwarderRegistryTest extends TestCase
{
    private ForwarderRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ForwarderRegistry();
    }

    /**
     * The first five are the actual PTR records that the 2026-07-27 digest
     * reported to a user as sources to "fix".
     *
     * @return array<string, array{string}>
     */
    public static function forwarderHostnameProvider(): array
    {
        return [
            'security gateway, EU region' => ['eu.cloud-sec-av.com'],
            'security gateway, CA region — rewrites the body, so both checks fail' => ['ca.cloud-sec-av.com'],
            'security gateway, US region' => ['us.cloud-sec-av.com'],
            'inky phish fence' => ['ipw-outbound.inkyphishfence.com'],
            'microsoft 365 forwarding' => ['mail-dm2pr04cu00304.outbound.protection.outlook.com'],
            'mimecast' => ['eu-smtp-delivery-1.mimecast.com'],
            'proofpoint hosted' => ['mx0a-00191d01.pphosted.com'],
            'proofpoint' => ['relay.proofpoint.com'],
            'proofpoint essentials' => ['mx1.ppe-hosted.com'],
            'barracuda' => ['esa1.example.barracudanetworks.com'],
            'symantec message labs' => ['mail1.bemta23.messagelabs.com'],
            'cisco secure email' => ['esa.hc1234-47.iphmx.com'],
            'alias forwarding service' => ['mx1.improvmx.com'],
            'mailing list server' => ['lists.example.org'],
            'listserv host' => ['listserv.university.edu'],
            'mailman host' => ['mailman.example.net'],
        ];
    }

    #[Test]
    #[DataProvider('forwarderHostnameProvider')]
    public function recognisesRecipientSideGatewaysAndListsFromTheHostnameAlone(string $hostname): void
    {
        self::assertTrue(
            $this->registry->isForwarder($hostname),
            'A body-rewriting gateway fails both DKIM and SPF, so only the hostname can tell it apart from spoofing.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonForwarderHostnameProvider(): array
    {
        return [
            'own outbound relay' => ['mxb-2-904.seznam.cz'],
            'google' => ['mail-yw1-f169.google.com'],
            'sendgrid' => ['o123.mail.sendgrid.net'],
            'amazon ses' => ['a1-23.smtp-out.amazonses.com'],
            'unrelated host' => ['mail.example.com'],
            'lookalike domain must not match on a substring' => ['evil-cloud-sec-av.com'],
            'lookalike suffix must not match either' => ['notmimecast.com'],
            'label must match whole, not as a prefix' => ['listsomething.example.com'],
            'empty hostname' => [''],
            'whitespace only' => ['   '],
        ];
    }

    #[Test]
    #[DataProvider('nonForwarderHostnameProvider')]
    public function doesNotMistakeOrdinarySendersForForwarders(string $hostname): void
    {
        self::assertFalse($this->registry->isForwarder($hostname));
    }

    #[Test]
    public function matchesRegardlessOfCaseAndTrailingDot(): void
    {
        self::assertTrue($this->registry->isForwarder('EU.CLOUD-SEC-AV.COM.'));
    }

    #[Test]
    public function matchesTheForwarderDomainItselfNotOnlySubdomains(): void
    {
        self::assertTrue($this->registry->isForwarder('mimecast.com'));
    }
}
