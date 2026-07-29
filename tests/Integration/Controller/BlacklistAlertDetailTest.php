<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The blocklist alert page has to be readable by someone who has just been told
 * something is Critical. Named lists, whether delivery is actually at risk, and
 * a way to go and check for themselves.
 */
final class BlacklistAlertDetailTest extends WebTestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function openAlert(array $data, string $message = 'First paragraph.

Second paragraph.'): UuidInterface
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'bl-detail-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Blocklist Detail Team',
            slug: 'bl-detail-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'bl-detail-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::IpBlacklisted,
            severity: AlertSeverity::Critical,
            title: 'A server sending mail for '.$domain->domain.' is blocklisted',
            message: $message,
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/alerts/'.$alertId->toString());

        return $alertId;
    }

    #[Test]
    public function theListingPanelNamesEachBlocklistAndLinksItsDelistingPage(): void
    {
        $this->openAlert([
            'ip_address' => '203.0.113.5',
            'listed_on' => ['zen.spamhaus.org', 'dnsbl.sorbs.net'],
            'blocks_delivery' => true,
            'delisting_urls' => ['https://check.spamhaus.org/'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Spamhaus ZEN');
        self::assertSelectorTextContains('body', 'SORBS');
        self::assertSelectorExists('a[href="https://check.spamhaus.org/"]');
    }

    #[Test]
    public function aDeliveryBlockingListIsDistinguishedFromAnAdvisoryOne(): void
    {
        $this->openAlert([
            'ip_address' => '203.0.113.5',
            'listed_on' => ['zen.spamhaus.org', 'dnsbl.sorbs.net'],
            'blocks_delivery' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Queried by mailbox providers when accepting mail');
        self::assertSelectorTextContains('body', 'Advisory list');
    }

    #[Test]
    public function theMessageRendersAsSeparateParagraphs(): void
    {
        $this->openAlert(
            ['ip_address' => '203.0.113.5', 'listed_on' => ['zen.spamhaus.org']],
            "What this is.\n\nWhat it means.\n\nWhat to do.",
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'What this is.');
        self::assertSelectorTextContains('body', 'What to do.');
    }

    #[Test]
    public function keysAlreadyRenderedAsTheListingPanelAreNotRepeatedAsARawDump(): void
    {
        $this->openAlert([
            'ip_address' => '203.0.113.5',
            'listed_on' => ['zen.spamhaus.org'],
            'blocks_delivery' => true,
            'delisting_urls' => ['https://check.spamhaus.org/'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Delisting urls');
        self::assertSelectorTextNotContains('body', 'Blocks delivery');
        // The address is not duplicated elsewhere, so it still belongs in Details.
        self::assertSelectorTextContains('body', 'Ip address');
    }

    #[Test]
    public function anAlertWithNoContextDataStillRenders(): void
    {
        $this->openAlert([]);

        self::assertResponseIsSuccessful();
    }
}
