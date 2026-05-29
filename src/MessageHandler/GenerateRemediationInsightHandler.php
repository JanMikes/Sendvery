<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Message\GenerateRemediationInsight;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Generates and caches AI remediation guidance for a failing DNS record (async).
 * Skips non-AI teams cleanly. The copyable DNS records still come from PHP — the
 * AI only narrates.
 */
#[AsMessageHandler]
final readonly class GenerateRemediationInsightHandler
{
    public function __construct(
        private AiInsightsService $aiService,
        private TeamRepository $teams,
        private MonitoredDomainRepository $domains,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GenerateRemediationInsight $message): void
    {
        if (!$this->teams->get($message->teamId)->getSubscriptionPlan()->hasAi()) {
            return;
        }

        $domain = $this->domains->get($message->domainId);
        $check = $this->entityManager->find(DnsCheckResult::class, $message->dnsCheckResultId);

        $this->aiService->generateRemediationGuidance(
            $message->domainId,
            new DnsCheckFailure(
                recordType: strtoupper($message->recordType->value),
                domain: $domain->domain,
                details: null !== $check ? $this->describeIssues($check) : 'The record is invalid.',
            ),
        );
    }

    private function describeIssues(DnsCheckResult $check): string
    {
        $messages = [];
        foreach ($check->issues as $issue) {
            if (is_array($issue) && isset($issue['message']) && is_string($issue['message']) && '' !== $issue['message']) {
                $messages[] = $issue['message'];
            }
        }

        return [] === $messages ? 'The record is invalid.' : implode(' ', $messages);
    }
}
