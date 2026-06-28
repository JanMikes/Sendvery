<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DmarcReportProcessed;
use App\MessageHandler\RecommendPolicyUpgrade;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class RecommendPolicyUpgradeTest extends IntegrationTestCase
{
    #[Test]
    public function doesNotRecommendTighteningForManagedDomains(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(id: Uuid::uuid7(), name: 'Rec', slug: 'rec-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable(), dmarcPolicy: DmarcPolicy::None);
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $this->getService(RecommendPolicyUpgrade::class)(
            new DmarcReportProcessed($domainId, $domainId, 'google.com', 100, 100, 0),
        );
        $em->flush();

        // Sendvery drives the ramp — no "tighten your policy" nag for managed domains.
        self::assertCount(0, $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]));
    }
}
