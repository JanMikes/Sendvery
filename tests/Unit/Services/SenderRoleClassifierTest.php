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
            hostnameForwardConfirmed: true,
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
            hostnameForwardConfirmed: true,
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
            hostnameForwardConfirmed: true,
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
            hostnameForwardConfirmed: true,
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

        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('eu.cloud-sec-av.com', null, $clean, true));
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('ca.cloud-sec-av.com', null, $modifying, true));
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('us.cloud-sec-av.com', null, $modifying, true));
    }

    #[Test]
    public function refusesToTrustAForwarderHostnameThatCannotBeConfirmed(): void
    {
        // A PTR record is written by whoever holds the IP block, so claiming to
        // be Mimecast costs an attacker nothing — and Forwarder is the role that
        // suppresses the new-sender alert.
        $role = $this->classifier->classify(
            'eu-smtp-delivery-1.mimecast.com',
            null,
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 400),
            hostnameForwardConfirmed: false,
        );

        self::assertNotSame(SenderRole::Forwarder, $role);
        self::assertTrue(
            $role->warrantsAlert(),
            'Suppressing the alert on the strength of an unverified name is the whole vulnerability.',
        );
    }

    #[Test]
    public function doesNotTurnAnUnconfirmedHostnameIntoAnAccusation(): void
    {
        // Failing confirmation withholds trust; it does not manufacture
        // suspicion. Sendvery has spent a long time removing false alarms and
        // must not reintroduce them through the back door.
        $role = $this->classifier->classify(
            'eu-smtp-delivery-1.mimecast.com',
            null,
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 2),
            hostnameForwardConfirmed: false,
        );

        self::assertSame(SenderRole::Unknown, $role);
    }

    #[Test]
    public function stillIdentifiesACleanForwardWhenTheHostnameProvesNothing(): void
    {
        $cleanForward = new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 2);

        self::assertSame(
            SenderRole::Forwarder,
            $this->classifier->classify(null, null, $cleanForward, hostnameForwardConfirmed: false),
            'A DKIM signature that survives the hop is cryptographic proof of a relayed message; it needs no help from DNS.',
        );
        self::assertSame(
            SenderRole::Forwarder,
            $this->classifier->classify('eu-smtp-delivery-1.mimecast.com', null, $cleanForward, hostnameForwardConfirmed: false),
        );
    }

    #[Test]
    public function recognisesAKnownProviderWhenNothingSuggestsForwarding(): void
    {
        $role = $this->classifier->classify(
            'mxb-2-904.seznam.cz',
            'Seznam',
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 100.0, isAuthorized: false, totalMessages: 40),
            hostnameForwardConfirmed: true,
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
            hostnameForwardConfirmed: true,
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
            hostnameForwardConfirmed: true,
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
        self::assertSame(SenderRole::Unknown, $this->classifier->classify(null, null, null, false));
    }

    #[Test]
    public function classifiesFromTheHostnameAloneWhenTheCallerHasNoAuthEvidence(): void
    {
        self::assertSame(SenderRole::Forwarder, $this->classifier->classify('eu.cloud-sec-av.com', null, null, true));
        self::assertSame(SenderRole::Esp, $this->classifier->classify('mxb.seznam.cz', 'Seznam', null, true));
        self::assertSame(SenderRole::Unknown, $this->classifier->classify('mail.nowhere.example', null, null, true));
    }

    #[Test]
    public function theSharedCacheNeverStoresOneTeamsVerdictAboutASender(): void
    {
        $authorizedAndFailing = new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: true, totalMessages: 400);

        self::assertSame(
            SenderRole::Esp,
            $this->classifier->baselineRole('mxb.seznam.cz', 'Seznam', true),
            'The global row describes the host, not what one team thinks of it.',
        );
        self::assertSame(
            SenderRole::OwnRelay,
            $this->classifier->classify('mxb.seznam.cz', 'Seznam', $authorizedAndFailing, true),
            'The same host is that team\'s own relay once their signals are applied.',
        );
    }

    #[Test]
    public function refusesToTrustAnEspHostnameThatCannotBeConfirmed(): void
    {
        // The wider half of the same hole. `organization` is resolved from the
        // PTR and nothing else, and Esp suppresses the new-sender alert exactly
        // as Forwarder does — but OrganizationMapper recognises ~60 names to the
        // forwarder registry's handful, so claiming to be SendGrid was the
        // cheaper way to buy silence.
        $spoofing = new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 400);

        self::assertSame(
            SenderRole::Esp,
            $this->classifier->classify('o1.ptr.sendgrid.net', 'SendGrid', $spoofing, true),
            'A confirmed provider hostname is still recognised — this must not cost real ESPs their identity.',
        );

        $forged = $this->classifier->classify('o1.ptr.sendgrid.net', 'SendGrid', $spoofing, false);

        self::assertNotSame(SenderRole::Esp, $forged);
        self::assertTrue(
            $forged->warrantsAlert(),
            'A sender failing every check while calling itself SendGrid is exactly what the alert exists to surface.',
        );
    }

    #[Test]
    public function theSharedCacheWithholdsForwarderTrustFromAnUnconfirmedHostname(): void
    {
        self::assertSame(
            SenderRole::Forwarder,
            $this->classifier->baselineRole('eu.cloud-sec-av.com', null, true),
        );
        self::assertSame(
            SenderRole::Unknown,
            $this->classifier->baselineRole('eu.cloud-sec-av.com', null, false),
            'The role cached for everybody must not be bought with a reverse record its owner wrote themselves.',
        );
    }
}
