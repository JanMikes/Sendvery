<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CheckAllBlacklistsCommand;
use App\Entity\BlacklistCheckResult;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Message\CheckBlacklist;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Blacklist monitoring was sold, priced, scored and rendered — and never ran.
 *
 * `CheckBlacklist`, `CheckBlacklistHandler`, `AlertOnBlacklisting`, the
 * `blacklist_check_result` table and the DNSBL client all existed. Nothing
 * anywhere dispatched the message, so the table was permanently empty while the
 * feature was listed in the Personal plan, described in the pricing FAQ as
 * continuously checking and raising alerts, and marked done on the roadmap.
 * This command is the missing link.
 *
 * WHICH IPs: the addresses the domain's own DMARC reports show sending as it,
 * newest first and capped. That is what a customer means by "is my mail getting
 * blocked", and it reuses evidence already collected rather than guessing at
 * hosts. The cap and the shared per-IP cache exist because public DNSBLs —
 * Spamhaus and Barracuda especially — rate-limit and will null-route a noisy
 * resolver, which would take the feature down for every customer at once.
 */
final class CheckAllBlacklistsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function everySendingIpOnAPaidDomainIsQueuedForChecking(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->paidDomainWithSenders($em, ['198.51.100.10', '198.51.100.11']);
        $em->flush();

        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);

        $dispatched = $this->dispatchedIps();
        sort($dispatched);

        self::assertSame(
            ['198.51.100.10', '198.51.100.11'],
            $dispatched,
            'A feature customers pay for has to actually run. Every IP seen sending as the domain must be queued.',
        );
    }

    #[Test]
    public function aFreeTeamIsNotChecked(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $this->paidDomainWithSenders($em, ['198.51.100.20'], SubscriptionPlan::Free);
        $em->flush();

        $this->tester()->execute([]);

        self::assertSame(
            [],
            $this->dispatchedIps(),
            'Blacklist monitoring is gated behind a paid plan in PlanLimits. Checking free teams anyway would spend a rate-limited shared resource on capacity nobody bought.',
        );
    }

    #[Test]
    public function anIpCheckedRecentlyIsNotCheckedAgain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->paidDomainWithSenders($em, ['198.51.100.30']);

        $em->persist(new BlacklistCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            ipAddress: '198.51.100.30',
            checkedAt: $this->getService(\Psr\Clock\ClockInterface::class)->now()->modify('-2 hours'),
            results: [],
            isListed: false,
        ));
        $em->flush();

        $this->tester()->execute([]);

        self::assertSame(
            [],
            $this->dispatchedIps(),
            'Public DNSBLs rate-limit and null-route noisy resolvers. Re-querying an address checked two hours ago spends that budget for no new information and risks the feature for every customer.',
        );
    }

    #[Test]
    public function aStaleCheckIsRefreshed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->paidDomainWithSenders($em, ['198.51.100.40']);

        $em->persist(new BlacklistCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            ipAddress: '198.51.100.40',
            checkedAt: $this->getService(\Psr\Clock\ClockInterface::class)->now()->modify('-3 days'),
            results: [],
            isListed: false,
        ));
        $em->flush();

        $this->tester()->execute([]);

        self::assertSame(
            ['198.51.100.40'],
            $this->dispatchedIps(),
            'A listing can appear at any time, so a three-day-old verdict is not a current one. Caching must expire or the feature reports history as if it were now.',
        );
    }

    #[Test]
    public function theNumberOfIpsCheckedPerDomainIsCapped(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $ips = [];
        for ($i = 1; $i <= 25; ++$i) {
            $ips[] = '203.0.113.'.$i;
        }
        $this->paidDomainWithSenders($em, $ips);
        $em->flush();

        $this->tester()->execute([]);

        // Asserted as an exact count, not an upper bound: `<= 10` would also
        // pass if the sweep dispatched nothing at all, which is how a cap test
        // quietly becomes a test that the feature is switched off.
        self::assertCount(
            CheckAllBlacklistsCommand::PER_DOMAIN_CAP,
            $this->dispatchedIps(),
            'One domain with a large sender inventory must not be able to exhaust the shared DNSBL query budget for every other customer — but it must still get its most recent senders checked.',
        );
    }

    /**
     * @param list<string> $ips
     */
    private function paidDomainWithSenders(
        EntityManagerInterface $em,
        array $ips,
        SubscriptionPlan $plan = SubscriptionPlan::Pro,
    ): MonitoredDomain {
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $persona->team->plan = $plan->value;

        $domain = $persona->domain;
        assert($domain instanceof MonitoredDomain);

        $now = $this->getService(\Psr\Clock\ClockInterface::class)->now();

        foreach ($ips as $index => $ip) {
            $em->persist(new KnownSender(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                sourceIp: $ip,
                hostname: null,
                organization: null,
                label: null,
                isAuthorized: false,
                firstSeenAt: $now->modify('-30 days'),
                lastSeenAt: $now->modify(sprintf('-%d hours', $index)),
                totalMessages: 100,
                passRate: 100.0,
            ));
        }

        return $domain;
    }

    /**
     * @return list<string>
     */
    private function dispatchedIps(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        $ips = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckBlacklist) {
                $ips[] = $message->ipAddress;
            }
        }

        return $ips;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('sendvery:blacklist:check-all'));
    }
}
