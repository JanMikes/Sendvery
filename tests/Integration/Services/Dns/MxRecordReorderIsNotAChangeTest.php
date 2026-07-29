<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Dns\DnsMonitor;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A DNS resolver may return an RRset in any order, and round-robin rotation
 * between equal-priority MX records is normal behaviour rather than a fault.
 *
 * Change detection compared the serialised answer string, so a domain with
 * several MX records at the same priority produced a "MX record changed"
 * warning on most nights, showing the user two identical record sets in a
 * different order. Every false alarm makes the next real one easier to ignore.
 */
final class MxRecordReorderIsNotAChangeTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    private const string DOMAIN = 'reorder-test.example';

    /** @param list<string> $hosts */
    private function scriptMx(array $hosts, int $priority = 10): void
    {
        $this->scriptDns()->reset();
        foreach ($hosts as $host) {
            $this->scriptDns()->withMx(self::DOMAIN, $host, $priority);
        }
    }

    private function persistDomain(): MonitoredDomain
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'MX Reorder Team',
            slug: 'mx-reorder-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: self::DOMAIN,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    /** @param array<DnsCheckResult> $results */
    private static function mxResult(array $results): DnsCheckResult
    {
        foreach ($results as $result) {
            if (DnsCheckType::Mx === $result->type) {
                return $result;
            }
        }

        self::fail('No MX check result was produced.');
    }

    /**
     * @param list<string> $firstAnswer
     * @param list<string> $secondAnswer
     *
     * @return array{DnsCheckResult, DnsCheckResult}
     */
    private function checkTwice(array $firstAnswer, array $secondAnswer): array
    {
        $em = $this->getService(EntityManagerInterface::class);
        $monitor = $this->getService(DnsMonitor::class);
        $domain = $this->persistDomain();

        $this->scriptMx($firstAnswer);
        $first = self::mxResult($monitor->check($domain));
        $em->persist($first);
        $em->flush();

        $this->scriptMx($secondAnswer);
        $second = self::mxResult($monitor->check($domain));
        $em->persist($second);
        $em->flush();

        return [$first, $second];
    }

    #[Test]
    public function theSameMxServersInADifferentOrderIsNotAChange(): void
    {
        // The exact answer pair from the user's report: four hosts, all at
        // priority 10, rotated by the resolver between two nightly sweeps.
        [, $second] = $this->checkTwice(
            ['email2.webglobe.cz', 'email.webglobe.cz', 'email3.webglobe.cz', 'email4.webglobe.cz'],
            ['email2.webglobe.cz', 'email3.webglobe.cz', 'email4.webglobe.cz', 'email.webglobe.cz'],
        );

        self::assertFalse(
            $second->hasChanged,
            'A rotated RRset is the same set of mail servers and must not raise an alert.',
        );
    }

    #[Test]
    public function theStoredRecordIsOrderStableSoTheDiffHasNothingToShow(): void
    {
        [$first, $second] = $this->checkTwice(
            ['email2.webglobe.cz', 'email.webglobe.cz', 'email3.webglobe.cz'],
            ['email3.webglobe.cz', 'email2.webglobe.cz', 'email.webglobe.cz'],
        );

        self::assertSame(
            $first->rawRecord,
            $second->rawRecord,
            'Serialisation must not depend on resolver answer order.',
        );
    }

    #[Test]
    public function aHostnameInDifferentCaseIsNotAChange(): void
    {
        // DNS hostnames are case-insensitive and 0x20-encoding resolvers echo
        // the case of the query back, so case drift is not an edit either.
        [, $second] = $this->checkTwice(
            ['email.webglobe.cz', 'email2.webglobe.cz'],
            ['Email.Webglobe.CZ', 'EMAIL2.webglobe.cz'],
        );

        self::assertFalse($second->hasChanged);
    }

    #[Test]
    public function actuallyAddingAMailServerStillCountsAsAChange(): void
    {
        [, $second] = $this->checkTwice(
            ['email.webglobe.cz', 'email2.webglobe.cz'],
            ['email2.webglobe.cz', 'email.webglobe.cz', 'email3.webglobe.cz'],
        );

        self::assertTrue(
            $second->hasChanged,
            'Suppressing reorder noise must not suppress a genuine MX edit.',
        );
    }

    #[Test]
    public function actuallyRemovingAMailServerStillCountsAsAChange(): void
    {
        [, $second] = $this->checkTwice(
            ['email.webglobe.cz', 'email2.webglobe.cz', 'email3.webglobe.cz'],
            ['email3.webglobe.cz', 'email.webglobe.cz'],
        );

        self::assertTrue($second->hasChanged);
    }

    #[Test]
    public function changingAPriorityStillCountsAsAChange(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $monitor = $this->getService(DnsMonitor::class);
        $domain = $this->persistDomain();

        $this->scriptMx(['email.webglobe.cz', 'email2.webglobe.cz'], priority: 10);
        $em->persist(self::mxResult($monitor->check($domain)));
        $em->flush();

        $this->scriptMx(['email.webglobe.cz', 'email2.webglobe.cz'], priority: 20);
        $second = self::mxResult($monitor->check($domain));

        self::assertTrue(
            $second->hasChanged,
            'Priority decides which server mail is offered to first — a change there is real.',
        );
    }

    #[Test]
    public function repointingAMailServerStillCountsAsAChange(): void
    {
        [, $second] = $this->checkTwice(
            ['email.webglobe.cz', 'email2.webglobe.cz'],
            ['email.webglobe.cz', 'mail.attacker.example'],
        );

        self::assertTrue(
            $second->hasChanged,
            'An MX hijack is the reason this alert exists — it must survive the fix.',
        );
    }
}
