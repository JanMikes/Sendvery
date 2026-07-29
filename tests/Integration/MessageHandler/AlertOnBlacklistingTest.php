<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\BlacklistCheckCompleted;
use App\MessageHandler\AlertOnBlacklisting;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A blocklist alert has to answer the three questions the reader actually has:
 * what is this address, is my mail actually affected, and what do I do?
 *
 * The version that shipped answered none of them — a red Critical badge over a
 * bare IP and a list of DNSBL hostnames — and the reported reaction was panic.
 */
final class AlertOnBlacklistingTest extends IntegrationTestCase
{
    /** @return array{Team, MonitoredDomain} */
    private function createTeamAndDomain(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Blacklist Alert Test',
            slug: 'blacklist-alert-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'blacklist-alert-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }

    private function knownSender(MonitoredDomain $domain, string $ip, ?string $organization, ?string $hostname): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $ip,
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable(),
            totalMessages: 1200,
            passRate: 99.1,
            hostname: $hostname,
            organization: $organization,
        );
        $em->persist($sender);
        $em->flush();
    }

    /** @param list<string> $listedOn */
    private function raise(MonitoredDomain $domain, string $ip, array $listedOn, bool $isListed = true): ?Alert
    {
        $em = $this->getService(EntityManagerInterface::class);

        $this->getService(AlertOnBlacklisting::class)(new BlacklistCheckCompleted(
            domainId: $domain->id,
            ipAddress: $ip,
            isListed: $isListed,
            listedOn: $listedOn,
        ));
        $em->flush();

        return $em->getRepository(Alert::class)->findOneBy(['monitoredDomain' => $domain->id->toString()]);
    }

    #[Test]
    public function aListingOnADeliveryBlockingListIsCritical(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.5', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertSame(AlertSeverity::Critical, $alert->severity);
        self::assertTrue($alert->data['blocks_delivery']);
    }

    #[Test]
    public function aListingOnlyOnAnAdvisoryListIsAWarningNotACritical(): void
    {
        // PSBL and UCEPROTECT are not queried by mailbox providers at SMTP
        // time. Paging someone at the same urgency as a Spamhaus listing is
        // how an alert channel stops being believed.
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.6', ['psbl.surriel.com', 'dnsbl-1.uceprotect.net']);

        self::assertNotNull($alert);
        self::assertSame(AlertSeverity::Warning, $alert->severity);
        self::assertFalse($alert->data['blocks_delivery']);
    }

    #[Test]
    public function theAlertNamesTheListInsteadOfOnlyItsHostname(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.7', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertStringContainsString('Spamhaus ZEN', $alert->message);
    }

    #[Test]
    public function theAlertExplainsThatTheAddressSendsMailRatherThanReceivingIt(): void
    {
        // The user read "blacklisted for <domain>" as being about their MX.
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.8', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertStringContainsString('sends email for', $alert->message);
        self::assertStringContainsString('not about your MX records', $alert->message);
    }

    #[Test]
    public function anAddressOperatedByAProviderSaysTheUserCannotDelistItThemselves(): void
    {
        [, $domain] = $this->createTeamAndDomain();
        $this->knownSender($domain, '77.75.78.89', 'Seznam.cz', 'mx1.emailprofi.seznam.cz');

        $alert = $this->raise($domain, '77.75.78.89', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertStringContainsString('Seznam.cz', $alert->message);
        self::assertStringContainsString('cannot request removal yourself', $alert->message);
        self::assertSame('Seznam.cz', $alert->data['operated_by']);
        self::assertSame('mx1.emailprofi.seznam.cz', $alert->data['sending_host']);
    }

    #[Test]
    public function aSenderWeOnlyHaveAHostnameForIsStillIdentifiedByThatHostname(): void
    {
        // Reverse DNS often resolves before the ASN-to-organisation mapping
        // does. A hostname is still far more use to the reader than nothing.
        [, $domain] = $this->createTeamAndDomain();
        $this->knownSender($domain, '203.0.113.20', null, 'mail.someprovider.example');

        $alert = $this->raise($domain, '203.0.113.20', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertStringContainsString('mail.someprovider.example', $alert->message);
        // Without an operator we cannot claim it is somebody else's to delist.
        self::assertStringContainsString('If you operate this server', $alert->message);
    }

    #[Test]
    public function anUnattributedAddressGetsSelfServeDelistingAdvice(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.9', ['zen.spamhaus.org']);

        self::assertNotNull($alert);
        self::assertStringContainsString('If you operate this server', $alert->message);
    }

    #[Test]
    public function theAlertCarriesADelistingUrlForEveryListItNames(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        $alert = $this->raise($domain, '203.0.113.11', ['zen.spamhaus.org', 'b.barracudacentral.org']);

        self::assertNotNull($alert);
        self::assertCount(2, $alert->data['delisting_urls']);
    }

    #[Test]
    public function aCheckThatFoundNothingRaisesNoAlert(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        self::assertNull($this->raise($domain, '203.0.113.12', [], isListed: false));
    }

    /**
     * Belt and braces on the production incident: even if `isListed` were ever
     * true again with no list actually naming the address, no alert may fire.
     */
    #[Test]
    public function anEmptyListingSetNeverRaisesAnAlert(): void
    {
        [, $domain] = $this->createTeamAndDomain();

        self::assertNull($this->raise($domain, '203.0.113.13', [], isListed: true));
    }
}
