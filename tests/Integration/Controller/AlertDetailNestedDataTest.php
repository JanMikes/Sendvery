<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The alert detail page's "Details" table renders whatever the raising handler
 * put in `alert.data`, and that payload is not flat: {@see \App\MessageHandler\AlertOnNewSender}
 * stores `new_senders` as a list of maps, each carrying its own `source_ips`
 * list.
 *
 * The table used to treat every iterable as one flat row of badges, so a nested
 * map reached `{{ item }}`, PHP raised "Array to string conversion" and the page
 * 500'd — on an entirely ordinary alert. Every demo-seed payload is flat, which
 * is why the suite never saw it.
 */
final class AlertDetailNestedDataTest extends WebTestCase
{
    #[Test]
    public function anAlertCarryingNestedDataRendersInsteadOfCrashing(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        $client->loginUser($persona->user);
        $alertId = $this->persistNewSenderAlert($persona);

        $crawler = $client->request('GET', '/app/alerts/'.$alertId);

        self::assertResponseIsSuccessful('A nested data payload must not take the alert page down.');
        $details = $crawler->filter('table')->text();
        self::assertStringNotContainsString('Array', $details, 'No value may be stringified as the literal "Array".');
    }

    #[Test]
    public function nestedValuesAreShownWithTheirOwnLabels(): void
    {
        // The point of the Details table is that the reader can see the payload.
        // Silently dropping the nested part would stop the crash and still lose
        // exactly the detail the alert is about.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        $client->loginUser($persona->user);
        $alertId = $this->persistNewSenderAlert($persona);

        $crawler = $client->request('GET', '/app/alerts/'.$alertId);

        $details = $crawler->filter('table')->text();
        self::assertStringContainsString('mailchimp', $details, 'Nested map values stay visible.');
        self::assertStringContainsString('198.51.100.7', $details, 'A list nested two levels down stays visible.');
        self::assertStringContainsString('Source ips', $details, 'Nested keys keep their humanised label.');
    }

    #[Test]
    public function aFlatListOfValuesIsStillRenderedAsBadges(): void
    {
        // Regression guard for the common shape — most payloads are flat, and
        // the recursion must not change how they look.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        $client->loginUser($persona->user);
        $alertId = $this->persistAlert($persona, ['observed_selectors' => ['s1', 's2']]);

        $crawler = $client->request('GET', '/app/alerts/'.$alertId);

        self::assertCount(2, $crawler->filter('table .badge'), 'Scalar list items remain badges.');
    }

    private function persistNewSenderAlert(\App\Tests\Fixtures\Persona $persona): string
    {
        return $this->persistAlert($persona, [
            'new_senders' => [
                [
                    'identity' => 'mailchimp',
                    'label' => 'Mailchimp',
                    'role' => 'marketing',
                    'messages' => 412,
                    'source_ips' => ['198.51.100.7', '198.51.100.8'],
                ],
            ],
            'reporter_org' => 'google.com',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function persistAlert(\App\Tests\Fixtures\Persona $persona, array $data): string
    {
        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $persona->team,
            monitoredDomain: $persona->domain,
            type: AlertType::NewUnknownSender,
            severity: AlertSeverity::Warning,
            title: 'New sender detected',
            message: 'First time your team has seen Mailchimp sending as this domain.',
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->persist($alert);
        $em->flush();

        return $alertId->toString();
    }
}
