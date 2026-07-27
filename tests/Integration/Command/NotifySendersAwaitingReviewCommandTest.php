<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\NotifySendersAwaitingReviewCommand;
use App\Entity\Alert;
use App\Entity\Team;
use App\Services\IdentityProvider;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\SenderReviewState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * The dedicated "your senders are piling up" email.
 *
 * The behaviours that matter are: it only fires when the unreviewed volume is
 * actually material, it cannot nag, it respects the per-user email-alerts
 * switch, and running it twice sends one email.
 */
final class NotifySendersAwaitingReviewCommandTest extends IntegrationTestCase
{
    #[Test]
    public function emailsTheTeamWhenAHighVolumeSenderIsWaitingForADecision(): void
    {
        $persona = $this->personaWith(
            'review-reminder-big',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.89', totalMessages: 640, organization: 'Seznam', hostname: 'mxb.seznam.cz')
                ->build(),
        );

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));

        $email = $this->findEmailTo($persona->user->email);
        self::assertNotNull($email, 'A sender carrying 640 messages that nobody vouched for is worth an email.');
        self::assertStringContainsString('waiting for your review', (string) $email->getSubject());
        self::assertStringContainsString('Seznam', (string) $email->getHtmlBody());
        self::assertStringContainsString('Seznam', (string) $email->getTextBody());
        self::assertStringContainsString(
            'filter=needs_review',
            (string) $email->getHtmlBody(),
            'The email has to land the reader on the filtered list, not the dashboard root.',
        );
    }

    /**
     * Volume, not count: a handful of one-message senders is the noise this
     * threshold exists to swallow.
     */
    #[Test]
    public function staysSilentWhenTheUnreviewedSendersCarryNoMeaningfulVolume(): void
    {
        $persona = $this->personaWith(
            'review-reminder-noise',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('198.51.100.1', totalMessages: 1)
                ->withKnownSender('198.51.100.2', totalMessages: 1)
                ->withKnownSender('198.51.100.3', totalMessages: 1)
                ->withKnownSender('203.0.113.1', totalMessages: 9000, reviewState: SenderReviewState::Authorized)
                ->build(),
        );

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));

        self::assertNull($this->findEmailTo($persona->user->email));
        self::assertStringContainsString('No team has unreviewed senders material enough', $tester->getDisplay());
    }

    /**
     * A domain with more distinct unreviewed senders than the email names must
     * say so, in both bodies — an email that silently truncates understates the
     * work waiting for the reader.
     */
    #[Test]
    public function namesASampleAndReportsHowManyMoreAreWaiting(): void
    {
        $persona = $this->personaWith(
            'review-reminder-truncated',
            static function (TestFixtures $f, string $prefix): Persona {
                $builder = $f->persona()->emailPrefix($prefix);
                for ($index = 0; $index < 8; ++$index) {
                    $builder->withKnownSender(
                        '203.0.113.'.(140 + $index),
                        totalMessages: 100 - $index,
                        organization: 'Provider-'.$index,
                    );
                }

                return $builder->build();
            },
        );

        self::assertSame(0, $this->tester()->execute([]));

        $email = $this->findEmailTo($persona->user->email);
        self::assertNotNull($email);
        self::assertStringContainsString('+3 more', (string) $email->getHtmlBody());
        self::assertStringContainsString('+3 more', (string) $email->getTextBody());
    }

    #[Test]
    public function staysSilentWhenEverySenderHasAlreadyBeenDecided(): void
    {
        $persona = $this->personaWith(
            'review-reminder-settled',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('203.0.113.2', totalMessages: 900, reviewState: SenderReviewState::Authorized)
                ->withKnownSender('203.0.113.3', totalMessages: 900, reviewState: SenderReviewState::NotAuthorized)
                ->build(),
        );

        self::assertSame(0, $this->tester()->execute([]));
        self::assertNull($this->findEmailTo($persona->user->email));
    }

    #[Test]
    public function runningTwiceInARowSendsExactlyOneEmail(): void
    {
        $persona = $this->personaWith(
            'review-reminder-idempotent',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.91', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        $afterFirstRun = count(self::getMailerMessages());

        self::assertSame(0, $this->tester()->execute([]));

        self::assertCount(
            $afterFirstRun,
            self::getMailerMessages(),
            'The reminder must not nag: a second run over unchanged data sends nothing.',
        );
    }

    #[Test]
    public function aTeamNotifiedWithinTheLastMonthIsNotEmailedAgain(): void
    {
        $persona = $this->personaWith(
            'review-reminder-deduped',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.92', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $this->persistPriorNotification($persona->team, new \DateTimeImmutable('-3 days'));

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));

        self::assertNull($this->findEmailTo($persona->user->email));
        self::assertStringContainsString('already notified within 30 days', $tester->getDisplay());
    }

    /**
     * The per-report new-sender alerts share {@see AlertType::NewUnknownSender}.
     * If the dedupe check keyed on the type alone, one report ingest would
     * suppress this notification forever.
     */
    #[Test]
    public function ordinaryNewSenderAlertsDoNotSuppressTheReminder(): void
    {
        $persona = $this->personaWith(
            'review-reminder-typeclash',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.93', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $em = $this->getService(EntityManagerInterface::class);
        $alert = new Alert(
            id: $this->getService(IdentityProvider::class)->nextIdentity(),
            team: $persona->team,
            monitoredDomain: $persona->domain,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: '1 new sender(s) detected',
            message: 'Seeded by report ingest.',
            data: ['new_sender_ips' => ['77.75.78.93']],
            createdAt: new \DateTimeImmutable('-1 day'),
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        self::assertSame(0, $this->tester()->execute([]));
        self::assertNotNull($this->findEmailTo($persona->user->email));
    }

    #[Test]
    public function aNotificationOlderThanTheDedupeWindowAllowsAFreshReminder(): void
    {
        $persona = $this->personaWith(
            'review-reminder-expired',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.94', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $this->persistPriorNotification($persona->team, new \DateTimeImmutable('-40 days'));

        self::assertSame(0, $this->tester()->execute([]));
        self::assertNotNull($this->findEmailTo($persona->user->email));
    }

    #[Test]
    public function aUserWhoTurnedEmailAlertsOffIsNotReachedThroughThisSideDoor(): void
    {
        $persona = $this->personaWith(
            'review-reminder-optout',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.95', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $em = $this->getService(EntityManagerInterface::class);
        $persona->user->emailAlertsEnabled = false;
        $em->flush();

        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));

        self::assertNull($this->findEmailTo($persona->user->email));
        self::assertStringContainsString('nobody has email alerts enabled', $tester->getDisplay());
    }

    #[Test]
    public function theInAppAlertRecordsTheSameThingTheEmailSays(): void
    {
        $persona = $this->personaWith(
            'review-reminder-alert',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.96', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        self::assertSame(0, $this->tester()->execute([]));

        $em = $this->getService(EntityManagerInterface::class);
        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $persona->team]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::NewUnknownSender, $alerts[0]->type);
        self::assertStringContainsString('waiting for your review', $alerts[0]->title);
        self::assertSame(
            NotifySendersAwaitingReviewCommand::NOTIFICATION_KEY,
            $alerts[0]->data['notification'] ?? null,
        );
    }

    #[Test]
    public function dryRunReportsWhatWouldBeSentAndSendsNothing(): void
    {
        $persona = $this->personaWith(
            'review-reminder-dryrun',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.97', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--dry-run' => true]));

        self::assertNull($this->findEmailTo($persona->user->email));
        self::assertStringContainsString('Would email', $tester->getDisplay());
        self::assertStringContainsString('Would notify 1 team', $tester->getDisplay());
    }

    #[Test]
    public function theTeamOptionNarrowsTheRunToOneTeam(): void
    {
        $target = $this->personaWith(
            'review-reminder-target',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.98', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );
        $other = $this->personaWith(
            'review-reminder-other',
            static fn (TestFixtures $f, string $prefix): Persona => $f->persona()
                ->emailPrefix($prefix)
                ->withKnownSender('77.75.78.99', totalMessages: 500, organization: 'Seznam')
                ->build(),
        );

        self::assertSame(0, $this->tester()->execute(['--team' => $target->team->id->toString()]));

        self::assertNotNull($this->findEmailTo($target->user->email));
        self::assertNull($this->findEmailTo($other->user->email));
    }

    /**
     * @param callable(TestFixtures, string): Persona $build
     */
    private function personaWith(string $prefix, callable $build): Persona
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        return $build($fixtures, $prefix);
    }

    private function persistPriorNotification(Team $team, \DateTimeImmutable $createdAt): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $alert = new Alert(
            id: $this->getService(IdentityProvider::class)->nextIdentity(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: '3 senders waiting for your review',
            message: 'Seeded prior notification.',
            data: ['notification' => NotifySendersAwaitingReviewCommand::NOTIFICATION_KEY],
            createdAt: $createdAt,
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();
    }

    private function findEmailTo(string $recipient): ?Email
    {
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $recipient) {
                    return $message;
                }
            }
        }

        return null;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:senders:review-reminder'));
    }
}
