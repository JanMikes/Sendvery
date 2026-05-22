<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Smoke + scenario coverage for the /app/domains/{id}/* subpages that the
 * generic RouteSmokeTest cannot exercise (path parameter required).
 */
final class DomainSubpagesTest extends WebTestCase
{
    /**
     * Subpages that 404 when the domain id is unknown.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function strictDomainSubpathProvider(): iterable
    {
        yield 'blacklist' => ['/app/domains/%s/blacklist'];
        yield 'dns-history' => ['/app/domains/%s/dns-history'];
        yield 'health' => ['/app/domains/%s/health'];
        yield 'senders' => ['/app/domains/%s/senders'];
    }

    /**
     * Subpages whose controller doesn't look up the domain entity and so
     * renders fine even for unknown ids — included only in the render-smoke
     * coverage, not the 404 cases.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function lenientDomainSubpathProvider(): iterable
    {
        yield 'reports' => ['/app/domains/%s/reports'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allDomainSubpathProvider(): iterable
    {
        yield from self::strictDomainSubpathProvider();
        yield from self::lenientDomainSubpathProvider();
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('allDomainSubpathProvider')]
    public function subpageRendersForOwner(string $pathTemplate): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', sprintf($pathTemplate, $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('strictDomainSubpathProvider')]
    public function subpageReturns404ForUnknownDomain(string $pathTemplate): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', sprintf($pathTemplate, Uuid::uuid7()->toString()));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('allDomainSubpathProvider')]
    public function subpageRedirectsAnonymousToLogin(string $pathTemplate): void
    {
        $client = self::createClient();
        $client->request('GET', sprintf($pathTemplate, Uuid::uuid7()->toString()));

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function pdfExportSucceedsForPaidPlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('personal')->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/export/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function pdfExportRedirectsForFreePlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('free')->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/export/pdf');

        // Plan gate sends users back to the domain detail page with a flash.
        self::assertResponseRedirects('/app/domains/'.$persona->domain->id);
    }

    #[Test]
    public function pdfExportReturns404ForUnknownDomainOnPaidPlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('business')->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains/'.Uuid::uuid7().'/export/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reverifyPostRedirectsForOwner(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('POST', '/app/domains/'.$persona->domain->id.'/reverify');

        self::assertResponseRedirects();
    }

    #[Test]
    public function adminCanAccessDomainSubpages(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->role(TeamRole::Admin)->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/health');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function memberCanAccessDomainSubpages(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->role(TeamRole::Member)->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/blacklist');

        self::assertResponseIsSuccessful();
    }
}
