<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Onboarding;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\ScriptsDnsRecords;
use App\Tests\WebTestCase;
use App\Value\Dns\DmarcSetupMode;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class OnboardingIngestionManagedVerifyTest extends WebTestCase
{
    use ScriptsDnsRecords;

    #[Test]
    public function rendersVerifiedWhenTheCnameResolves(): void
    {
        $client = self::createClient();
        [$user, $domain] = $this->managedDomain();
        $this->scriptDns()->withCname('_dmarc.'.$domain, $domain.'._dmarc.sendvery.test');

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Managed DMARC is live for '.$domain, (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function keepsPollingWhenTheCnameIsMissing(): void
    {
        $client = self::createClient();
        [$user, $domain] = $this->managedDomain();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Checking your CNAME', (string) $client->getResponse()->getContent());
        unset($domain);
    }

    #[Test]
    public function explainsWhenTheDmarcPointsElsewhere(): void
    {
        $client = self::createClient();
        [$user, $domain] = $this->managedDomain();
        $this->scriptDns()->withCname('_dmarc.'.$domain, '_dmarc.someotherprovider.example');

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('points somewhere else', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function blocksWhileACoexistingDmarcTxtIsLive(): void
    {
        $client = self::createClient();
        [$user, $domain] = $this->managedDomain();
        // A TXT and no CNAME — RFC 1034 forbids coexistence, so the user must remove the TXT first.
        $this->scriptDns()->withTxt('_dmarc.'.$domain, 'v=DMARC1; p=reject; rua=mailto:dmarc@'.$domain);

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Remove your existing DMARC TXT first', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function ingestionRendersTheManagedOptionWithEnableForPaidPlans(): void
    {
        $client = self::createClient();
        [$user] = $this->managedDomain(managed: false);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="onboarding-managed-option"]'));
        self::assertCount(1, $crawler->filter('form input[name="method"][value="managed"]'));
    }

    #[Test]
    public function postingMethodManagedEnablesManagedDmarc(): void
    {
        $client = self::createClient();
        [$user, $domainName] = $this->managedDomain(managed: false);

        $client->loginUser($user);
        $client->request('POST', '/app/onboarding/ingestion', ['method' => 'managed']);
        self::assertResponseRedirects('/app/onboarding/ingestion');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();
        $domain = $em->getRepository(MonitoredDomain::class)->findOneBy(['domain' => $domainName]);
        self::assertNotNull($domain);
        self::assertSame(DmarcSetupMode::ManagedCname, $domain->dmarcSetupMode);
    }

    #[Test]
    public function redirectsCompletedUsersToTheDashboard(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $userId = Uuid::uuid7();
        $user = new User(id: $userId, email: 'done-'.$userId->toString().'@example.com', createdAt: new \DateTimeImmutable(), onboardingCompletedAt: new \DateTimeImmutable());
        $user->popEvents();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function redirectsToDomainStepWhenNoDomain(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $userId = Uuid::uuid7();
        $user = new User(id: $userId, email: 'nodomain-'.$userId->toString().'@example.com', createdAt: new \DateTimeImmutable(), onboardingTeamCompletedAt: new \DateTimeImmutable());
        $user->popEvents();
        $em->persist($user);
        $team = new Team(id: Uuid::uuid7(), name: 'No Domain', slug: 'no-domain-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable());
        $team->popEvents();
        $em->persist($team);
        $em->persist(new TeamMembership(id: Uuid::uuid7(), user: $user, team: $team, role: TeamRole::Owner, joinedAt: new \DateTimeImmutable()));
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion/managed-verify');

        self::assertResponseRedirects('/app/onboarding/domain');
    }

    /** @return array{0: User, 1: string} */
    private function managedDomain(bool $managed = true): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(id: $userId, email: 'managed-'.$userId->toString().'@example.com', createdAt: new \DateTimeImmutable(), onboardingTeamCompletedAt: new \DateTimeImmutable());
        $user->popEvents();
        $em->persist($user);

        $team = new Team(id: Uuid::uuid7(), name: 'Managed Onboard', slug: 'managed-onboard-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $team->popEvents();
        $em->persist($team);
        $em->persist(new TeamMembership(id: Uuid::uuid7(), user: $user, team: $team, role: TeamRole::Owner, joinedAt: new \DateTimeImmutable()));

        $domainName = 'managed-onboard-'.substr(Uuid::uuid7()->toString(), 0, 8).'.example';
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: $domainName, createdAt: new \DateTimeImmutable());
        if ($managed) {
            $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
            $domain->managedPolicyP = \App\Value\DmarcPolicy::None;
        }
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$user, $domainName];
    }
}
