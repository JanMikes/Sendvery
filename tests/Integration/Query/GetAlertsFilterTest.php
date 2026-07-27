<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\AlertNotFound;
use App\Query\GetAlertDetail;
use App\Query\GetAlerts;
use App\Repository\AlertRepository;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The narrowing filters and the tenant guards on the alerts read side. A caller
 * with no team memberships must always come back empty rather than seeing
 * everyone's alerts.
 */
final class GetAlertsFilterTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    private GetAlerts $query;

    private Persona $persona;

    protected function setUp(): void
    {
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->query = $this->getService(GetAlerts::class);
        $this->persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix('alert-filter')
            ->build();
    }

    private function persistAlert(
        string $title,
        AlertType $type = AlertType::FailureSpike,
        bool $isRead = false,
        ?MonitoredDomain $domain = null,
        ?Team $team = null,
    ): UuidInterface {
        $id = Uuid::uuid7();
        $alert = new Alert(
            id: $id,
            team: $team ?? $this->persona->team,
            monitoredDomain: $domain,
            type: $type,
            severity: AlertSeverity::Warning,
            title: $title,
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: $isRead,
        );
        $alert->popEvents();
        $this->em->persist($alert);
        $this->em->flush();

        return $id;
    }

    #[Test]
    public function aCallerWithoutTeamsSeesNoAlertsAndNoCounts(): void
    {
        $this->persistAlert('Someone elses alert');

        self::assertSame([], $this->query->forTeams([]));
        self::assertSame(0, $this->query->countUnreadForTeams([]));
        self::assertSame(0, $this->query->countUnreadCriticalForTeams([]));
    }

    #[Test]
    public function filteringByTypeNarrowsToThatAlertType(): void
    {
        $this->persistAlert('Spike', AlertType::FailureSpike);
        $this->persistAlert('New sender', AlertType::NewUnknownSender);

        $results = $this->query->forTeams([$this->persona->team->id->toString()], type: AlertType::NewUnknownSender->value);

        self::assertCount(1, $results);
        self::assertSame('New sender', $results[0]->title);
    }

    #[Test]
    public function filteringByDomainNarrowsToThatDomainsAlerts(): void
    {
        $domain = $this->persona->domain;
        self::assertNotNull($domain);

        $this->persistAlert('Domain alert', domain: $domain);
        $this->persistAlert('Account-wide alert');

        $results = $this->query->forTeams([$this->persona->team->id->toString()], domainId: $domain->id->toString());

        self::assertCount(1, $results);
        self::assertSame('Domain alert', $results[0]->title);
    }

    #[Test]
    public function theReadFilterSplitsOpenedFromUnopenedAlerts(): void
    {
        $this->persistAlert('Unopened', isRead: false);
        $this->persistAlert('Opened', isRead: true);

        $teamIds = [$this->persona->team->id->toString()];

        $unread = $this->query->forTeams($teamIds, isRead: false);
        $read = $this->query->forTeams($teamIds, isRead: true);

        self::assertCount(1, $unread);
        self::assertSame('Unopened', $unread[0]->title);
        self::assertCount(1, $read);
        self::assertSame('Opened', $read[0]->title);
    }

    #[Test]
    public function theAlertDetailIsHiddenFromACallerWithoutTeams(): void
    {
        $id = $this->persistAlert('Detail alert');

        self::assertNull($this->getService(GetAlertDetail::class)->forAlert($id->toString(), []));
    }

    #[Test]
    public function theTeamScopedLookupRefusesACallerWithoutTeams(): void
    {
        $id = $this->persistAlert('Scoped alert');

        self::assertNull($this->getService(AlertRepository::class)->findForTeams($id, []));
    }

    #[Test]
    public function theSystemLookupFailsLoudlyForAnUnknownAlert(): void
    {
        // Internal callers pass ids from trusted state, so a miss is a bug —
        // it must throw rather than silently no-op.
        $this->expectException(AlertNotFound::class);

        $this->getService(AlertRepository::class)->get(Uuid::uuid7());
    }
}
