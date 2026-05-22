<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\BetaAccessRequest;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class AnnounceLaunchToBetaLeadsCommandTest extends IntegrationTestCase
{
    public function testReportsZeroWhenNoLeadsFound(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--since' => '2030-01-01']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No beta-access leads to notify.', $tester->getDisplay());
    }

    public function testDryRunListsLeadsWithoutSendingLaunchEmail(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $idProvider = $this->getService(IdentityProvider::class);

        $em->persist(new BetaAccessRequest(
            id: $idProvider->nextIdentity(),
            email: 'lead-a@example.com',
            name: 'Alice Lead',
            company: null,
            requestedPlan: SubscriptionPlan::Pro,
            domainCount: 5,
            message: null,
            source: 'pricing',
            requestedAt: new \DateTimeImmutable('2026-05-15 10:00:00'),
        ));
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['--since' => '2026-05-14', '--dry-run' => true]);

        self::assertSame(0, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('lead-a@example.com', $output);
        self::assertStringContainsString('--dry-run: no emails sent.', $output);

        // The BetaAccessRequested event fires on persist and sends 2 admin/
        // ack emails — that's expected. The launch announcement must NOT
        // be among them in dry-run mode.
        self::assertSame(0, $this->countEmailsWithSubject('Sendvery is open'));
    }

    public function testSendsLaunchAnnouncementEmail(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $idProvider = $this->getService(IdentityProvider::class);

        $em->persist(new BetaAccessRequest(
            id: $idProvider->nextIdentity(),
            email: 'recipient@example.com',
            name: 'Recipient Name',
            company: 'Acme',
            requestedPlan: SubscriptionPlan::Personal,
            domainCount: 3,
            message: null,
            source: 'pricing',
            requestedAt: new \DateTimeImmutable('2026-05-15 10:00:00'),
        ));
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['--since' => '2026-05-14', '--coupon' => 'LAUNCH20']);

        self::assertSame(0, $exit);

        // Find the launch announcement addressed to our specific recipient
        // — other tests in the same fixture window can leave BetaAccess
        // rows behind in the cached test DB.
        $myLaunch = null;
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }
            if (!str_contains((string) $message->getSubject(), 'Sendvery is open')) {
                continue;
            }
            if ('recipient@example.com' !== $message->getTo()[0]->getAddress()) {
                continue;
            }
            $myLaunch = $message;

            break;
        }

        self::assertNotNull($myLaunch);
        $body = (string) $myLaunch->getTextBody();
        self::assertStringContainsString('personal', $body);
        self::assertStringContainsString('LAUNCH20', $body);
    }

    private function countEmailsWithSubject(string $needle): int
    {
        return count(array_filter(
            self::getMailerMessages(),
            static fn ($message): bool => $message instanceof Email
                && str_contains((string) $message->getSubject(), $needle),
        ));
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:beta-leads:launch-announce'));
    }
}
