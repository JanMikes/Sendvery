<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Events\AutoRampAdvanceScheduled;
use App\Events\AutoRampPaused;
use App\Events\CnameVerified;
use App\Events\DmarcPolicyChanged;
use App\Events\ManagedDmarcBecameReady;
use App\Events\ManagedDmarcDanglingDetected;
use App\MessageHandler\NotifyTeamWhenAutoRampPaused;
use App\MessageHandler\NotifyTeamWhenAutoRampScheduled;
use App\MessageHandler\NotifyTeamWhenCnameVerified;
use App\MessageHandler\NotifyTeamWhenManagedDmarcDangling;
use App\MessageHandler\NotifyTeamWhenManagedDmarcReady;
use App\MessageHandler\NotifyTeamWhenPolicyAdvanced;
use App\Services\IdentityProvider;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ManagedDmarcNotificationsTest extends WebTestCase
{
    public function testCnameVerifiedSendsTheLiveEmail(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenCnameVerified::class)(new CnameVerified($domain->id, $team->id, 'acme.example'));

        self::assertEmailCount(1);
        self::assertStringContainsString('Managed DMARC is live for acme.example', $this->firstSubject());
    }

    public function testAutoRampScheduledSendsThe48hNotice(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenAutoRampScheduled::class)(new AutoRampAdvanceScheduled($domain->id, $team->id, 'acme.example', AutoRampStage::Quarantine, new \DateTimeImmutable('2026-07-03 09:00:00')));

        self::assertEmailCount(1);
        self::assertStringContainsString('moves to quarantine in 48 hours', $this->firstSubject());
    }

    public function testPolicyAdvancedSendsTheAdvancedEmailAndInfoAlert(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenPolicyAdvanced::class)(new DmarcPolicyChanged($domain->id, $team->id, 'acme.example', new ManagedDmarcPolicy(DmarcPolicy::None), new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::AutoRamp, null));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertEmailCount(1);
        self::assertStringContainsString('acme.example is now at quarantine', $this->firstSubject());
        self::assertSame(AlertType::ManagedDmarcAdvanced, $this->onlyAlert($team)->type);
        self::assertSame(AlertSeverity::Info, $this->onlyAlert($team)->severity);
    }

    public function testPolicyAdvancedDoesNotEmailOnLoosening(): void
    {
        [$domain, $team] = $this->managedDomain();

        // A rollback (reject -> quarantine) is loosening, not an advance.
        $this->getService(NotifyTeamWhenPolicyAdvanced::class)(new DmarcPolicyChanged($domain->id, $team->id, 'acme.example', new ManagedDmarcPolicy(DmarcPolicy::Reject), new ManagedDmarcPolicy(DmarcPolicy::Quarantine), PolicyChangeSource::Rollback, null));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertEmailCount(0);
    }

    public function testPolicyAdvancedToRejectUsesFullEnforcementCopy(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenPolicyAdvanced::class)(new DmarcPolicyChanged($domain->id, $team->id, 'acme.example', new ManagedDmarcPolicy(DmarcPolicy::Quarantine), new ManagedDmarcPolicy(DmarcPolicy::Reject), PolicyChangeSource::AutoRamp, null));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertEmailCount(1);
        self::assertStringContainsString('acme.example is now at reject', $this->firstSubject());
    }

    public function testNoAdvanceEmailWhenChangingToNone(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenPolicyAdvanced::class)(new DmarcPolicyChanged($domain->id, $team->id, 'acme.example', new ManagedDmarcPolicy(DmarcPolicy::Quarantine), new ManagedDmarcPolicy(DmarcPolicy::None), PolicyChangeSource::Rollback, null));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertEmailCount(0);
    }

    public function testAlertHandlersSkipAMissingDomain(): void
    {
        self::createClient();
        $missing = Uuid::uuid7();
        $team = Uuid::uuid7();

        $this->getService(NotifyTeamWhenPolicyAdvanced::class)(new DmarcPolicyChanged($missing, $team, 'gone.example', null, new ManagedDmarcPolicy(DmarcPolicy::Reject), PolicyChangeSource::AutoRamp, null));
        $this->getService(NotifyTeamWhenManagedDmarcReady::class)(new ManagedDmarcBecameReady($missing, $team, 'gone.example', DmarcPolicy::Quarantine));
        $this->getService(NotifyTeamWhenAutoRampPaused::class)(new AutoRampPaused($missing, $team, 'gone.example', 'Alignment dropped'));
        $this->getService(NotifyTeamWhenManagedDmarcDangling::class)(new ManagedDmarcDanglingDetected($missing, $team, 'gone.example'));

        self::assertEmailCount(0);
    }

    public function testMailerSkipsWhenNoSubscribedRecipients(): void
    {
        self::createClient();
        $em = $this->getService(EntityManagerInterface::class);

        // A team with no alert-subscribed members.
        $team = new Team(id: Uuid::uuid7(), name: 'Silent', slug: 'silent-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);
        $domain = new MonitoredDomain(id: Uuid::uuid7(), team: $team, domain: 'silent.example', createdAt: new \DateTimeImmutable());
        $em->persist($domain);
        $em->flush();

        $this->getService(NotifyTeamWhenCnameVerified::class)(new CnameVerified($domain->id, $team->id, 'silent.example'));

        self::assertEmailCount(0);
    }

    public function testReadyToAdvanceSendsTheReadyEmailAndInfoAlert(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenManagedDmarcReady::class)(new ManagedDmarcBecameReady($domain->id, $team->id, 'acme.example', DmarcPolicy::Quarantine));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertEmailCount(1);
        self::assertStringContainsString('acme.example is ready for quarantine', $this->firstSubject());
        self::assertSame(AlertType::ManagedDmarcReady, $this->onlyAlert($team)->type);
    }

    public function testRegressionRaisesACriticalAlertThatAlsoEmails(): void
    {
        [$domain, $team] = $this->managedDomain();

        // Create the regression alert, then run the critical-email handler over it.
        $this->getService(NotifyTeamWhenAutoRampPaused::class)(new AutoRampPaused($domain->id, $team->id, 'acme.example', 'Alignment dropped to 70%'));
        $this->getService(EntityManagerInterface::class)->flush();

        $alert = $this->onlyAlert($team);
        self::assertSame(AlertType::ManagedDmarcRegression, $alert->type);
        self::assertSame(AlertSeverity::Critical, $alert->severity);

        // The Critical alert flows through the existing critical-email path on flush.
        self::assertEmailCount(1);
        self::assertStringContainsString('We paused DMARC enforcement on acme.example', $this->firstSubject());
    }

    public function testUserInitiatedPauseRaisesNoRegressionAlert(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenAutoRampPaused::class)(new AutoRampPaused($domain->id, $team->id, 'acme.example', \App\MessageHandler\ConfigureDmarcAutoRampHandler::USER_PAUSE_REASON));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertCount(0, $this->getService(EntityManagerInterface::class)->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]));
    }

    public function testDanglingRaisesACriticalAlertThatAlsoEmails(): void
    {
        [$domain, $team] = $this->managedDomain();

        $this->getService(NotifyTeamWhenManagedDmarcDangling::class)(new ManagedDmarcDanglingDetected($domain->id, $team->id, 'acme.example'));
        $this->getService(EntityManagerInterface::class)->flush();

        $alert = $this->onlyAlert($team);
        self::assertSame(AlertType::ManagedDmarcDangling, $alert->type);
        self::assertSame(AlertSeverity::Critical, $alert->severity);

        self::assertEmailCount(1);
        self::assertStringContainsString('points to Sendvery but isn’t managed', $this->firstSubject());
    }

    private function firstSubject(): string
    {
        $message = self::getMailerMessages()[0];
        assert($message instanceof \Symfony\Component\Mime\Email);

        return (string) $message->getSubject();
    }

    private function onlyAlert(Team $team): Alert
    {
        $alerts = $this->getService(EntityManagerInterface::class)->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);

        return $alerts[0];
    }

    /** @return array{MonitoredDomain, Team} */
    private function managedDomain(): array
    {
        self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $identityProvider = $this->getService(IdentityProvider::class);

        $team = new Team(id: Uuid::uuid7(), name: 'Notify', slug: 'notify-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);

        $user = new User(id: $identityProvider->nextIdentity(), email: 'owner-'.bin2hex(random_bytes(4)).'@example.com', createdAt: new \DateTimeImmutable());
        $em->persist($user);
        $em->persist(new TeamMembership(id: $identityProvider->nextIdentity(), user: $user, team: $team, role: TeamRole::Owner, joinedAt: new \DateTimeImmutable()));

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->autoRampStage = AutoRampStage::Monitoring;
        $em->persist($domain);
        $em->flush();

        return [$domain, $team];
    }
}
