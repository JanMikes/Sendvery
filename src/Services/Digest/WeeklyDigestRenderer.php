<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Entity\Team;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Value\RenderedWeeklyDigest;
use App\Value\WeeklyDigestSections;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Turns a team into the weekly digest email it would receive — both
 * alternatives, and the subject line — without sending anything.
 *
 * Rendering lives here rather than in {@see \App\MessageHandler\SendWeeklyDigestHandler}
 * so that the preview path (`sendvery:digest:send-all --preview`) produces the
 * real email rather than an approximation of it. Every digest defect this
 * quarter shipped because nobody looked at a rendered one; a preview that
 * rendered through a second code path would only move the problem.
 */
final readonly class WeeklyDigestRenderer
{
    public function __construct(
        private WeeklyDigestGenerator $digestGenerator,
        private WeeklyDigestPlainTextRenderer $plainTextRenderer,
        private AiInsightsService $aiService,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $withAiSummary false renders the digest a non-AI team would
     *                            get. Only the preview passes false: an AI
     *                            narration is a paid provider call per team,
     *                            and a review tool that costs money to run is
     *                            a review tool nobody runs.
     */
    public function render(Team $team, bool $withAiSummary = true): RenderedWeeklyDigest
    {
        $digest = $this->digestGenerator->generate($team);

        // Absolute URLs in a CLI/worker context come from
        // framework.router.default_uri (env DEFAULT_URI) — there is no incoming
        // request to derive a host from. If those links ever come out as
        // localhost in production, that env var is the thing to fix.
        $dashboardUrl = $this->urlGenerator->generate(
            'dashboard_overview',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $alertsUrl = $this->urlGenerator->generate(
            'dashboard_alerts',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $dateRange = sprintf(
            '%s — %s',
            $digest->periodStart->format('M j'),
            $digest->periodEnd->format('M j, Y'),
        );

        $aiSummary = $withAiSummary ? $this->aiSummary($team) : null;

        // Resolved once and handed to both renderers: each section used to
        // decide its own visibility twice, and the two copies drifted.
        $sections = WeeklyDigestSections::of($digest, null !== $aiSummary);

        return new RenderedWeeklyDigest(
            subject: sprintf('Sendvery Weekly Report — %s — %s', $digest->teamName, $dateRange),
            html: $this->twig->render('emails/weekly_digest.html.twig', [
                'digest' => $digest,
                'sections' => $sections,
                'dashboardUrl' => $dashboardUrl,
                'alertsUrl' => $alertsUrl,
                'dateRange' => $dateRange,
                'aiSummary' => $aiSummary,
            ]),
            text: $this->plainTextRenderer->render(
                digest: $digest,
                sections: $sections,
                dashboardUrl: $dashboardUrl,
                alertsUrl: $alertsUrl,
                dateRange: $dateRange,
                aiSummary: $aiSummary,
            ),
        );
    }

    private function aiSummary(Team $team): ?WeeklyDigestResult
    {
        // Plan-gated: only AI teams get a summary. The hasAi() guard means the
        // gated service won't refuse, so no AiNotEnabledForPlan handling is needed.
        if (!$team->getSubscriptionPlan()->hasAi()) {
            return null;
        }

        // The AI narration is additive garnish on a digest that is already
        // complete without it. A failing upstream call (expired key, rate limit,
        // provider outage) used to bubble out of the handler and abort the whole
        // send, so an AI-plan team got NO email at all — strictly worse than the
        // free-plan behaviour they are paying to improve on. Degrade to the
        // plain digest instead and leave a trail for whoever is on call.
        try {
            return $this->aiService->generateWeeklyDigest($team->id);
        } catch (\Throwable $exception) {
            $this->logger->error('Weekly digest AI summary failed; sending the digest without it.', [
                'teamId' => $team->id->toString(),
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
