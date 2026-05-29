<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\ReportNotAnalyzable;
use App\Repository\TeamRepository;
use App\Services\Ai\Analysis\ReportInsightAnalyzer;
use App\Services\Ai\Analysis\RoutineReportClassifier;
use App\Services\Ai\Analysis\WeeklyDigestDomainFact;
use App\Services\Ai\Analysis\WeeklyDigestFacts;
use App\Services\Ai\Client\AnthropicClient;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Prompt\AnomalyPrompt;
use App\Services\Ai\Prompt\RemediationPrompt;
use App\Services\Ai\Prompt\ReportExplanationPrompt;
use App\Services\Ai\Prompt\SenderLabelPrompt;
use App\Services\Ai\Prompt\WeeklyDigestPrompt;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\Ai\Security\RemediationRecordFactory;
use App\Services\Ai\Security\UntrustedDataSanitizer;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Services\OrganizationMapper;
use App\Value\AiInsightType;
use App\Value\WeeklyDigestData;
use Ramsey\Uuid\UuidInterface;

/**
 * The real AI implementation (DEC-057). Orchestrates the deterministic-first
 * pipeline — analyze in PHP, short-circuit routine reports with no API call,
 * otherwise build a hardened prompt, call the per-task model, and validate the
 * output. Contains no business math and no DNS-string generation; those live in
 * the analyzer and the remediation record factory.
 *
 * Wrapped by PlanGatedAiInsightsService (plan + quota) and CachingAiInsightsService
 * (durable cache) — this class is the innermost link.
 */
final readonly class AnthropicAiInsightsService implements AiInsightsService
{
    public function __construct(
        private AnthropicClient $client,
        private ReportInsightAnalyzer $analyzer,
        private RoutineReportClassifier $routineClassifier,
        private AiModelPolicy $modelPolicy,
        private AiResultMapper $mapper,
        private RemediationRecordFactory $remediationRecordFactory,
        private UntrustedDataSanitizer $sanitizer,
        private OrganizationMapper $organizationMapper,
        private WeeklyDigestGenerator $digestGenerator,
        private TeamRepository $teams,
    ) {
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        $facts = $this->analyzer->analyzeReport($reportId, $teamId);
        if (null === $facts) {
            throw new ReportNotAnalyzable(sprintf('Report %s is not analyzable for team %s.', $reportId->toString(), $teamId->toString()));
        }

        // ~95% of reports are routine (DEC-055): answer from a PHP template, no API call.
        if ($this->routineClassifier->isRoutine($facts)) {
            return $this->routineClassifier->buildTemplatedExplanation($facts);
        }

        $model = $this->modelPolicy->forTask(AiInsightType::ReportExplanation);
        $response = $this->client->requestStructuredOutput(
            $model,
            ReportExplanationPrompt::SYSTEM,
            ReportExplanationPrompt::tool(),
            ReportExplanationPrompt::userMessage($facts),
            $model->maxOutputTokens(),
        );

        return $this->mapper->toReportExplanation($response->toolInput);
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        $facts = $this->analyzer->analyzeReport($reportId, $teamId);
        if (null === $facts) {
            throw new ReportNotAnalyzable(sprintf('Report %s is not analyzable for team %s.', $reportId->toString(), $teamId->toString()));
        }

        $model = $this->modelPolicy->forTask(AiInsightType::AnomalyExplanation);
        $response = $this->client->requestStructuredOutput(
            $model,
            AnomalyPrompt::SYSTEM,
            AnomalyPrompt::tool(),
            AnomalyPrompt::userMessage($facts),
            $model->maxOutputTokens(),
        );

        return $this->mapper->toAnomaly($response->toolInput);
    }

    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        $team = $this->teams->get($teamId);
        $facts = $this->buildDigestFacts($this->digestGenerator->generate($team));

        $model = $this->modelPolicy->forTask(AiInsightType::WeeklyDigest);
        $response = $this->client->requestStructuredOutput(
            $model,
            WeeklyDigestPrompt::SYSTEM,
            WeeklyDigestPrompt::tool(),
            WeeklyDigestPrompt::userMessage($facts),
            $model->maxOutputTokens(),
        );

        return $this->mapper->toWeeklyDigest($response->toolInput);
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        // Records are generated and validated in PHP — never from the model.
        $records = $this->remediationRecordFactory->buildFor($failure);

        $model = $this->modelPolicy->forTask(AiInsightType::Remediation);
        $response = $this->client->requestStructuredOutput(
            $model,
            RemediationPrompt::SYSTEM,
            RemediationPrompt::tool(),
            RemediationPrompt::userMessage(
                $failure->recordType,
                $this->sanitizer->sanitize($failure->domain),
                $this->sanitizer->sanitize($failure->details, 300),
            ),
            $model->maxOutputTokens(),
        );

        return $this->mapper->toRemediation($response->toolInput, $records);
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        // Deterministic first: a known ESP domain needs no API call.
        $deterministic = $this->organizationMapper->resolve($domain);
        if (null !== $deterministic) {
            return new SenderLabelResult($deterministic, 1.0);
        }

        $model = $this->modelPolicy->forTask(AiInsightType::SenderLabel);
        $response = $this->client->requestStructuredOutput(
            $model,
            SenderLabelPrompt::SYSTEM,
            SenderLabelPrompt::tool(),
            SenderLabelPrompt::userMessage($this->sanitizer->sanitizeIp($ip), $this->sanitizer->sanitize($domain), null),
            $model->maxOutputTokens(),
        );

        return $this->mapper->toSenderLabel($response->toolInput);
    }

    private function buildDigestFacts(WeeklyDigestData $data): WeeklyDigestFacts
    {
        $domains = [];
        foreach ($data->domains as $domain) {
            $domains[] = new WeeklyDigestDomainFact(
                domain: $this->sanitizer->sanitize($domain->domainName),
                messages: $domain->totalMessages,
                passRate: round($domain->passRate, 1),
                passRateDelta: null !== $domain->passRateDelta ? round($domain->passRateDelta, 1) : null,
                // Counts only — never the untrusted sender names themselves.
                newSenderCount: count($domain->newSenders),
                alertCount: count($domain->alerts),
            );
        }

        $brokenDns = [];
        foreach ($data->currentlyBrokenDns as $broken) {
            $brokenDns[] = $this->sanitizer->sanitize(sprintf('%s (%s)', $broken->domainName, $broken->checkType));
        }

        return new WeeklyDigestFacts(
            teamName: $this->sanitizer->sanitize($data->teamName),
            periodLabel: $data->periodStart->format('M j').' — '.$data->periodEnd->format('M j, Y'),
            totalDomains: $data->totalDomains,
            totalMessages: $data->totalMessages,
            averagePassRate: round($data->averagePassRate, 1),
            alertsCount: $data->alertsCount,
            dnsChangesCount: $data->dnsChangesCount,
            domains: $domains,
            brokenDns: $brokenDns,
        );
    }
}
