<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\ManagedDmarcEnabled;
use App\MessageHandler\PublishHostedDmarcRecordWhenManagedEnabled;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class PublishHostedDmarcRecordWhenManagedEnabledTest extends IntegrationTestCase
{
    #[Test]
    public function idStaysNullAndIsRetriedBySyncOnPublishFailure(): void
    {
        $publisher = $this->getService(FakeDnsRecordPublisher::class);
        $publisher->simulateFailure();

        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(id: Uuid::uuid7(), name: 'Publish Fail', slug: 'publish-fail-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->autoRampStage = AutoRampStage::Monitoring;
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $this->getService(PublishHostedDmarcRecordWhenManagedEnabled::class)(
            new ManagedDmarcEnabled($domainId, $team->id, 'acme.example'),
        );
        $em->flush();

        $reloaded = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertNull($reloaded->cloudflareHostedDmarcRecordId, 'On publish failure the id stays null so the sync cron retries.');

        $publisher->simulateSuccess();
    }

    #[Test]
    public function skipsAMissingDomainGracefully(): void
    {
        $this->getService(PublishHostedDmarcRecordWhenManagedEnabled::class)(
            new ManagedDmarcEnabled(Uuid::uuid7(), Uuid::uuid7(), 'gone.example'),
        );

        $this->expectNotToPerformAssertions();
    }
}
