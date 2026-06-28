<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Dashboard;

use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ManagedDmarcControllerTest extends WebTestCase
{
    #[Test]
    public function paidPlanRendersTheManagedCardWithTheEnableControl(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="managed-dmarc-card"]'));
        self::assertCount(1, $crawler->filter('form[action$="/managed-dmarc/enable"]'), 'A paid plan sees the Enable control.');
    }

    #[Test]
    public function freePlanSeesAnUpgradeNudgeInsteadOfTheManagedToggle(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('free')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="managed-dmarc-card"]'));
        self::assertCount(0, $crawler->filter('form[action$="/managed-dmarc/enable"]'), 'A Free plan must not get the Enable control.');
        self::assertStringContainsString('auto-drive', $crawler->filter('[data-testid="managed-dmarc-card"]')->text(), 'The Free-plan nudge names auto-drive.');
    }

    #[Test]
    public function paidPlanCanEnableManagedDmarc(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $domainId = $persona->domain->id;
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/enable"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/enable', $domainId->toString()), ['_csrf_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $domainId);
        self::assertNotNull($domain);
        self::assertSame(DmarcSetupMode::ManagedCname, $domain->dmarcSetupMode);
    }

    #[Test]
    public function aForgedPostForAnotherTeamsDomainIsRejected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $attacker = $fixtures->persona()->plan('pro')->emailPrefix('attacker')->build();
        $victim = $fixtures->persona()->plan('pro')->emailPrefix('victim')->build();
        assert(null !== $victim->domain);

        $client->loginUser($attacker->user);

        // Attacker (logged in) targets the victim's domain — the team-scoped load
        // must reject it as not-found, never act cross-tenant.
        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/enable', $victim->domain->id->toString()), [
            '_csrf_token' => $this->enableToken($client, $attacker),
        ]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function setPolicyControllerPublishesInstantly(): void
    {
        [$client, $domainId] = $this->activeManagedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/policy"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/policy', $domainId->toString()), [
            '_csrf_token' => $token,
            'policy' => 'quarantine',
            'subdomain_policy' => 'same',
            'pct' => '100',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        self::assertSame(\App\Value\DmarcPolicy::Quarantine, $this->reload($domainId)->managedPolicyP);
    }

    #[Test]
    public function advanceControllerRunsTheReadinessCheck(): void
    {
        [$client, $domainId] = $this->activeManagedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/advance"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/advance', $domainId->toString()), ['_csrf_token' => $token]);

        // No report data → not ready → no-op, but the route succeeds.
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(\App\Value\DmarcPolicy::None, $this->reload($domainId)->managedPolicyP);
    }

    #[Test]
    public function configureAutoRampControllerTurnsAutoDriveOn(): void
    {
        [$client, $domainId] = $this->activeManagedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/auto-ramp"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/auto-ramp', $domainId->toString()), [
            '_csrf_token' => $token,
            'action' => 'enable',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        self::assertTrue($this->reload($domainId)->autoRampEnabled);
    }

    #[Test]
    public function switchToSelfTxtControllerDisablesManaged(): void
    {
        [$client, $domainId] = $this->activeManagedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/switch-to-self"] input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/switch-to-self', $domainId->toString()), ['_csrf_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        self::assertSame(DmarcSetupMode::SelfTxt, $this->reload($domainId)->dmarcSetupMode);
    }

    #[Test]
    public function cardRendersEachManagedState(): void
    {
        // (mutator, expected data-managed-state)
        $cases = [
            ['preparing', static function (MonitoredDomain $d): void {
                $d->dmarcSetupMode = DmarcSetupMode::ManagedCname;
                $d->managedPolicyP = \App\Value\DmarcPolicy::None;
            }],
            ['cname_pending', static function (MonitoredDomain $d): void {
                $d->dmarcSetupMode = DmarcSetupMode::ManagedCname;
                $d->managedPolicyP = \App\Value\DmarcPolicy::None;
                $d->cloudflareHostedDmarcRecordId = 'cf-1';
            }],
            ['error', static function (MonitoredDomain $d): void {
                $d->dmarcSetupMode = DmarcSetupMode::ManagedCname;
                $d->managedPolicyP = \App\Value\DmarcPolicy::Quarantine;
                $d->cloudflareHostedDmarcRecordId = 'cf-1';
                $d->autoRampPausedAt = new \DateTimeImmutable('-1 hour');
            }],
        ];

        foreach ($cases as [$expectedState, $mutator]) {
            $client = self::createClient();
            $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
            assert(null !== $persona->domain);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            assert($em instanceof EntityManagerInterface);
            $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
            assert(null !== $domain);
            $mutator($domain);
            $em->flush();
            $client->loginUser($persona->user);

            $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));
            self::assertSame(
                $expectedState,
                $crawler->filter('[data-testid="managed-dmarc-card"]')->attr('data-managed-state'),
                sprintf('Card must render the %s state.', $expectedState),
            );
            self::ensureKernelShutdown();
        }
    }

    #[Test]
    public function cardRendersFrozenStateForADowngradedManagedDomain(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('free')->build();
        assert(null !== $persona->domain);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert(null !== $domain);
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = \App\Value\DmarcPolicy::Quarantine;
        $domain->cnameVerifiedAt = new \DateTimeImmutable('-1 day');
        $domain->cloudflareHostedDmarcRecordId = 'cf-1';
        $em->flush();
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));
        self::assertSame('frozen', $crawler->filter('[data-testid="managed-dmarc-card"]')->attr('data-managed-state'));
    }

    #[Test]
    public function setPolicyRejectsAnInvalidPolicy(): void
    {
        [$client, $domainId] = $this->activeManagedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId->toString()));
        $token = (string) $crawler->filter('form[action$="/managed-dmarc/policy"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/policy', $domainId->toString()), [
            '_csrf_token' => $token,
            'policy' => 'bogus',
            'pct' => '999',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // Invalid policy is rejected — nothing published.
        self::assertSame(\App\Value\DmarcPolicy::None, $this->reload($domainId)->managedPolicyP);
    }

    #[Test]
    public function writeRoutesRequireACsrfToken(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        foreach (['enable', 'policy', 'advance', 'auto-ramp', 'switch-to-self'] as $route) {
            $client->request('POST', sprintf('/app/domains/%s/managed-dmarc/%s', $persona->domain->id->toString(), $route));
            self::assertSame(403, $client->getResponse()->getStatusCode(), sprintf('POST /managed-dmarc/%s without a CSRF token must be refused.', $route));
        }
    }

    private function enableToken(KernelBrowser $client, \App\Tests\Fixtures\Persona $persona): string
    {
        assert(null !== $persona->domain);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        return (string) $crawler->filter('form[action$="/managed-dmarc/enable"] input[name="_csrf_token"]')->attr('value');
    }

    /** @return array{0: KernelBrowser, 1: \Ramsey\Uuid\UuidInterface} */
    private function activeManagedDomain(): array
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan('pro')->build();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert(null !== $domain);
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = \App\Value\DmarcPolicy::None;
        $domain->autoRampStage = \App\Value\Dns\AutoRampStage::Monitoring;
        $domain->cnameVerifiedAt = new \DateTimeImmutable('-1 day');
        $domain->cloudflareHostedDmarcRecordId = 'cf-active';
        $em->flush();

        $client->loginUser($persona->user);

        return [$client, $persona->domain->id];
    }

    private function reload(\Ramsey\Uuid\UuidInterface $domainId): MonitoredDomain
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $domainId);
        assert(null !== $domain);

        return $domain;
    }
}
