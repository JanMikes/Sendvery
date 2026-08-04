<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Dashboard;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\TestSupport\DuplicateKeyCommandBus;
use App\Tests\WebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;

/**
 * Adding a domain that is already connected.
 *
 * `monitored_domain` carries a system-wide unique index on `lower(domain)`, but
 * the add form only ever blocked names owned by *another* team. Re-submitting a
 * domain your own team already monitors therefore sailed past the guard and was
 * answered with a duplicate-key 500.
 *
 * Nothing is broken in that situation — the domain IS monitored, which is
 * precisely what the user asked for. So the page says so in a neutral tone and
 * links to the domain, rather than reporting the user's satisfied intent as an
 * error.
 */
final class AddDuplicateDomainTest extends WebTestCase
{
    #[Test]
    public function reAddingADomainTheTeamAlreadyMonitorsExplainsItInsteadOfCrashing(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())
            // Free allows a single domain, so a free team is stopped by the
            // plan cap long before it can reach the duplicate. The 500 needs a
            // plan with room to spare — which is where real users hit it.
            ->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('POST', '/app/domains/add', [
            'domain_name' => $persona->domain->domain,
        ]);

        self::assertResponseIsSuccessful('Re-adding an existing domain must not blow up with a duplicate-key error.');
        $notice = $crawler->filter('[data-testid="domain-already-connected"]');
        self::assertCount(1, $notice, 'The form must explain that the domain is already connected.');
        self::assertStringContainsString(
            $persona->domain->domain,
            $notice->text(),
            'The notice names the domain the user tried to add.',
        );
        self::assertCount(
            1,
            $notice->filter(sprintf('a[href="/app/domains/%s"]', $persona->domain->id->toString())),
            'The notice links to the domain the user is already monitoring.',
        );
    }

    #[Test]
    public function reAddingADomainLeavesTheSingleExistingRowUntouched(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('POST', '/app/domains/add', ['domain_name' => $persona->domain->domain]);

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM monitored_domain WHERE LOWER(domain) = :name',
                ['name' => $persona->domain->domain],
            ),
            'A duplicate submit must not append a second row for the same domain.',
        );
    }

    #[Test]
    public function theAlreadyConnectedNoticeIsInformationalRatherThanAnError(): void
    {
        // The domain being monitored is the outcome the user wanted. Painting
        // it red teaches them to distrust the alerts that do matter.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('POST', '/app/domains/add', ['domain_name' => $persona->domain->domain]);

        self::assertCount(
            1,
            $crawler->filter('[data-testid="domain-already-connected"].alert-info'),
            'An already-connected domain is informational, not a failure.',
        );
        self::assertCount(0, $crawler->filter('.alert-error'), 'Nothing has gone wrong, so no error alert is shown.');
    }

    #[Test]
    public function theDomainIsMatchedCaseInsensitivelyJustLikeTheUniqueIndex(): void
    {
        // The index is on lower(domain), so a differently-cased submit collides
        // in the database exactly the same way. The guard has to agree with it.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('POST', '/app/domains/add', [
            'domain_name' => strtoupper($persona->domain->domain),
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="domain-already-connected"]'));
    }

    #[Test]
    public function aDomainClaimedByAnotherTeamStillRoutesToTheTakenPage(): void
    {
        // The pre-existing hard block must survive the fix: someone else's
        // domain is a genuine conflict, and leaking "already connected" would
        // tell the user about a tenant they cannot see.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $stranger = $fixtures->persona()->emailPrefix('stranger')->build();
        assert(null !== $stranger->domain);
        $persona = $fixtures->persona()->plan('pro')->build();
        $client->loginUser($persona->user);

        $client->request('POST', '/app/domains/add', ['domain_name' => $stranger->domain->domain]);

        self::assertResponseRedirects('/app/domain-taken?domain='.$stranger->domain->domain);
    }

    #[Test]
    public function losingTheRaceToAConcurrentSubmitIsResolvedInsteadOfCrashing(): void
    {
        // Two submits in flight at once (double click, second tab) both clear
        // the duplicate check and only collide at INSERT. The failed flush
        // closes the EntityManager, so the owner cannot be re-read in this
        // request — /app/domain-taken resolves it in a fresh one and, for our
        // own team, forwards to the domain itself.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        $client->loginUser($persona->user);

        self::getContainer()->set('command_bus', new DuplicateKeyCommandBus());

        $client->request('POST', '/app/domains/add', ['domain_name' => 'raced.example']);

        self::assertResponseRedirects('/app/domain-taken?domain=raced.example');
    }
}
