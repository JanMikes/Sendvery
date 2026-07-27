<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Events\AlertCreated;
use App\MessageHandler\SendAlertEmailNotification;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Only genuine incidents earn an inbox interruption. Good news and
 * informational transitions stay in the dashboard.
 */
final class SendAlertEmailNotificationTest extends WebTestCase
{
    private Persona $persona;

    protected function setUp(): void
    {
        self::createClient();

        $this->persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix('alert-email')
            ->build();
    }

    private function raise(AlertType $type, AlertSeverity $severity, string $title): void
    {
        $this->getService(SendAlertEmailNotification::class)(new AlertCreated(
            alertId: Uuid::uuid7(),
            teamId: $this->persona->team->id,
            type: $type,
            severity: $severity,
            title: $title,
            domainName: $this->persona->domain?->domain,
        ));
    }

    public function testACriticalIncidentEmailsTheTeam(): void
    {
        $this->raise(AlertType::DnsRecordMissing, AlertSeverity::Critical, 'MX record removed for acme.example');

        self::assertEmailCount(1);
    }

    public function testGoodNewsNeverReachesTheInbox(): void
    {
        // A record going live is the outcome the user asked for — mailing them
        // about it would train them to ignore alert emails.
        $this->raise(AlertType::DnsRecordPublished, AlertSeverity::Success, 'DKIM record published for acme.example');

        self::assertEmailCount(0);
    }

    public function testAWarningNeverReachesTheInbox(): void
    {
        $this->raise(AlertType::DnsRecordChanged, AlertSeverity::Warning, 'SPF record changed for acme.example');

        self::assertEmailCount(0);
    }

    public function testNoEmailWhenNobodyOnTheTeamOptedIn(): void
    {
        $this->persona->user->emailAlertsEnabled = false;
        $this->getService(EntityManagerInterface::class)->flush();

        $this->raise(AlertType::DnsRecordMissing, AlertSeverity::Critical, 'MX record removed for acme.example');

        self::assertEmailCount(0);
    }
}
