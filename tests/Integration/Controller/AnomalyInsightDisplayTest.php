<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AiInsight;
use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\MutedAlert;
use App\Entity\Team;
use App\Services\Ai\AiInsightCacheKey;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AiInsightType;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AnomalyInsightDisplayTest extends WebTestCase
{
    public function testSpikeAlertWithAPrecomputedInsightShowsTheAiExplanationCard(): void
    {
        $client = self::createClient();
        $persona = $this->aiTeam('anomaly-show');
        $reportId = Uuid::uuid7()->toString();
        $alert = $this->persistAlert($persona, AlertType::FailureSpike, ['report_id' => $reportId]);
        $this->persistAnomalyInsight($persona->team, $reportId, 'A burst of unauthenticated mail appeared.', 'critical', 'Review your senders now.');

        $client->loginUser($persona->user);
        $client->request('GET', '/app/alerts/'.$alert->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'A burst of unauthenticated mail appeared.');
        self::assertSelectorTextContains('body', 'Review your senders now.');
    }

    public function testSpikeAlertWithoutAnInsightRendersWithoutTheCard(): void
    {
        $client = self::createClient();
        $persona = $this->aiTeam('anomaly-none');
        $alert = $this->persistAlert($persona, AlertType::FailureSpike, ['report_id' => Uuid::uuid7()->toString()]);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/alerts/'.$alert->id->toString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI explanation', (string) $client->getResponse()->getContent());
    }

    public function testSpikeAlertWithoutAReportIdInItsDataShowsNoCard(): void
    {
        $client = self::createClient();
        $persona = $this->aiTeam('anomaly-noreport');
        $alert = $this->persistAlert($persona, AlertType::FailureSpike, ['spike_amount' => 42.0]);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/alerts/'.$alert->id->toString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI explanation', (string) $client->getResponse()->getContent());
    }

    public function testNonSpikeAlertsNeverShowTheAnomalyCard(): void
    {
        $client = self::createClient();
        $persona = $this->aiTeam('anomaly-other');
        $alert = $this->persistAlert($persona, AlertType::DnsRecordChanged, ['report_id' => Uuid::uuid7()->toString()]);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/alerts/'.$alert->id->toString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI explanation', (string) $client->getResponse()->getContent());
    }

    public function testAMutedFailureSpikeTypeStillRendersWithTheUnmuteAction(): void
    {
        $client = self::createClient();
        $persona = $this->aiTeam('anomaly-muted');
        assert($persona->domain instanceof MonitoredDomain);
        $alert = $this->persistAlert($persona, AlertType::FailureSpike, ['report_id' => Uuid::uuid7()->toString()]);

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist(new MutedAlert(Uuid::uuid7(), $persona->team, $persona->domain, AlertType::FailureSpike, new \DateTimeImmutable()));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/alerts/'.$alert->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unmute');
    }

    private function aiTeam(string $prefix): Persona
    {
        return TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('AI '.$prefix)
            ->plan('personal_ai')->withDomain($prefix.'.example')->build();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistAlert(Persona $persona, AlertType $type, array $data): Alert
    {
        $em = $this->getService(EntityManagerInterface::class);
        assert($persona->domain instanceof MonitoredDomain);

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $persona->team,
            monitoredDomain: $persona->domain,
            type: $type,
            severity: AlertSeverity::Critical,
            title: 'Failure spike detected',
            message: 'A spike was detected.',
            data: $data,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($alert);
        $em->flush();

        return $alert;
    }

    private function persistAnomalyInsight(Team $team, string $reportId, string $explanation, string $severity, string $action): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist(new AiInsight(
            id: Uuid::uuid7(),
            team: $team,
            type: AiInsightType::AnomalyExplanation,
            subjectId: $reportId,
            cacheKey: AiInsightCacheKey::anomalyExplanation($reportId),
            content: ['explanation' => $explanation, 'severity' => $severity, 'recommendedAction' => $action],
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();
    }
}
