<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\ForwarderRegistry;
use App\Services\SenderRoleClassifier;
use App\Value\SenderAuthSignals;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SenderRoleClassifierTest extends TestCase
{
    private SenderRoleClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SenderRoleClassifier(new ForwarderRegistry());
    }

    #[Test]
    public function treatsAnAuthorizedSenderAsTheTeamsOwnRelay(): void
    {
        $role = $this->classifier->classify(
            'mxb-2-904.seznam.cz',
            'Seznam',
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 100.0, isAuthorized: true, totalMessages: 40),
        );

        self::assertSame(SenderRole::OwnRelay, $role);
    }

    #[Test]
    public function authorizationOutranksEveryOtherSignal(): void
    {
        $role = $this->classifier->classify(
            'mail-dm2pr04cu00304.outbound.protection.outlook.com',
            'Microsoft',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: true, totalMessages: 500),
        );

        self::assertSame(
            SenderRole::OwnRelay,
            $role,
            'A tenant sending through Microsoft 365 uses the same hostnames a forwarder does; the team saying "this is mine" settles it.',
        );
    }

    #[Test]
    public function identifiesAForwarderThatLeftTheBodyAloneFromItsAuthResults(): void
    {
        $role = $this->classifier->classify(
            'gateway.unmapped-appliance.example',
            null,
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 2),
        );

        self::assertSame(
            SenderRole::Forwarder,
            $role,
            'DKIM surviving while SPF breaks is the clean-forward signature.',
        );
    }

    #[Test]
    public function identifiesAForwarderThatRewroteTheBodyFromItsHostname(): void
    {
        $role = $this->classifier->classify(
            'ca.cloud-sec-av.com',
            null,
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 50),
        );

        self::assertSame(
            SenderRole::Forwarder,
            $role,
            'A gateway that rewrites links breaks DKIM as well as SPF; without the hostname check it would be reported as an attack.',
        );
    }

    #[Test]
    public function classifiesTheThreeRegionsOfOneGatewayIdentically(): void
    {
        $modifying = new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 1);
        $clean = new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 1);

        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('eu.cloud-sec-av.com', null, $clean));
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('ca.cloud-sec-av.com', null, $modifying));
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('us.cloud-sec-av.com', null, $modifying));
    }

    #[Test]
    public function recognisesAKnownProviderWhenNothingSuggestsForwarding(): void
    {
        $role = $this->classifier->classify(
            'mxb-2-904.seznam.cz',
            'Seznam',
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 100.0, isAuthorized: false, totalMessages: 40),
        );

        self::assertSame(SenderRole::Esp, $role);
    }

    #[Test]
    public function callsOutASenderThatFailsEverythingAtVolume(): void
    {
        $role = $this->classifier->classify(
            'mail.unrecognised-host.example',
            null,
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 400),
        );

        self::assertSame(SenderRole::Suspicious, $role);
    }

    #[Test]
    public function doesNotAccuseTheOneOrTwoMessageLongTailOfSpoofing(): void
    {
        $role = $this->classifier->classify(
            'mail.unrecognised-host.example',
            null,
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 2),
        );

        self::assertSame(
            SenderRole::Unknown,
            $role,
            'Spoofing campaigns are high volume; a two-message straggler from another continent is forwarding.',
        );
    }

    #[Test]
    public function leavesASenderUnidentifiedWhenNothingIsKnownAboutIt(): void
    {
        self::assertSame(SenderRole::Unknown, $this->classifier->classify(null, null, null));
    }

    #[Test]
    public function classifiesFromTheHostnameAloneWhenTheCallerHasNoAuthEvidence(): void
    {
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('eu.cloud-sec-av.com', null, null));
        self::assertSame(SenderRole::Esp, $this->classifier->classify('mxb.seznam.cz', 'Seznam', null));
        self::assertSame(SenderRole::Unknown, $this->classifier->classify('mail.nowhere.example', null, null));
    }

    #[Test]
    public function theSharedCacheNeverStoresOneTeamsVerdictAboutASender(): void
    {
        $authorizedAndFailing = new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: true, totalMessages: 400);

        self::assertSame(
            SenderRole::Esp,
            $this->classifier->baselineRole('mxb.seznam.cz', 'Seznam'),
            'The global row describes the host, not what one team thinks of it.',
        );
        self::assertSame(
            SenderRole::OwnRelay,
            $this->classifier->classify('mxb.seznam.cz', 'Seznam', $authorizedAndFailing),
            'The same host is that team\'s own relay once their signals are applied.',
        );
    }
}
