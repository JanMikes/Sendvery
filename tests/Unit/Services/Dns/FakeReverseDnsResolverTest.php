<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeReverseDnsResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeReverseDnsResolverTest extends TestCase
{
    #[Test]
    public function answersOnlyWithHostnamesATestScripted(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');

        self::assertSame('mxb.seznam.cz', $resolver->resolve('77.75.76.89'));
        self::assertNull($resolver->resolve('198.51.100.5'), 'Anything unscripted must look like an address with no PTR, never a real lookup.');
    }

    #[Test]
    public function scriptsAGenuineHostInBothDirectionsAtOnce(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');

        self::assertSame(
            ['77.75.76.89'],
            $resolver->forwardAddresses('mxb.seznam.cz'),
            'Real infrastructure resolves both ways, so a scripted host must too — otherwise every existing test would read as an attack.',
        );
    }

    #[Test]
    public function collectsEveryAddressScriptedOntoOneHostname(): void
    {
        $resolver = (new FakeReverseDnsResolver())
            ->withHostname('3.132.108.44', 'ipw-outbound.inkyphishfence.com')
            ->withHostname('34.210.15.192', 'ipw-outbound.inkyphishfence.com')
            ->withHostname('3.132.108.44', 'ipw-outbound.inkyphishfence.com');

        self::assertSame(
            ['3.132.108.44', '34.210.15.192'],
            $resolver->forwardAddresses('ipw-outbound.inkyphishfence.com'),
            'A gateway pool is one hostname with many addresses, and re-scripting one of them must not duplicate it.',
        );
    }

    #[Test]
    public function scriptsAReverseRecordThatClaimsAHostnameItCannotBackUp(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withForgedHostname('203.0.113.240', 'eu-smtp-delivery-1.mimecast.com');

        self::assertSame('eu-smtp-delivery-1.mimecast.com', $resolver->resolve('203.0.113.240'));
        self::assertSame([], $resolver->forwardAddresses('eu-smtp-delivery-1.mimecast.com'));
    }

    #[Test]
    public function letsATestStateAHostnamesOwnAddressesOutright(): void
    {
        $resolver = (new FakeReverseDnsResolver())
            ->withHostname('40.93.13.100', 'mail.outbound.protection.outlook.com')
            ->withForwardAddresses('mail.outbound.protection.outlook.com', '::ffff:40.93.13.100');

        self::assertSame(
            ['::ffff:40.93.13.100'],
            $resolver->forwardAddresses('mail.outbound.protection.outlook.com'),
            'Replacing rather than appending is what lets a test pin the exact answer a real resolver gives.',
        );
    }

    #[Test]
    public function reportsNoAddressesForAHostnameNobodyScripted(): void
    {
        self::assertSame([], (new FakeReverseDnsResolver())->forwardAddresses('nowhere.example'));
    }

    #[Test]
    public function countsLookupsSoTestsCanProveTheCacheIsWorking(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolver->resolve('77.75.76.89');
        $resolver->resolve('198.51.100.5');
        $resolver->forwardAddresses('mxb.seznam.cz');

        self::assertSame(2, $resolver->lookupCount());
        self::assertSame(1, $resolver->forwardLookupCount());
    }

    #[Test]
    public function forgetsEverythingWhenReset(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');
        $resolver->resolve('77.75.76.89');
        $resolver->forwardAddresses('mxb.seznam.cz');

        $resolver->reset();

        self::assertNull($resolver->resolve('77.75.76.89'));
        self::assertSame([], $resolver->forwardAddresses('mxb.seznam.cz'));
        self::assertSame(1, $resolver->lookupCount(), 'The counter restarts with the scripted hosts.');
        self::assertSame(1, $resolver->forwardLookupCount());
    }
}
