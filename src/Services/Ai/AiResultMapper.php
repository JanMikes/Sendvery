<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\KeyMetric;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\SuggestedDnsRecord;
use App\Services\Ai\Result\WeeklyDigestResult;

/**
 * Validates and normalizes the model's tool output into Result DTOs — the last
 * line of defense. Even though forced strict tool use fixes the shape, model
 * narration is still text that could echo injected content, so every string is
 * HTML-stripped, link-stripped, control-char-stripped, and length-capped;
 * enums are coerced to the allowed set; numbers are clamped; empty narration
 * falls back to a safe default. This never throws — a degraded-but-safe result
 * beats a 500 on a path the user already paid for.
 */
final readonly class AiResultMapper
{
    private const int MAX_NARRATION = 2000;
    private const int MAX_SHORT = 80;
    private const int MAX_RECOMMENDATION = 300;

    private const string SEVERITY_FALLBACK = 'info';
    private const array SEVERITIES = ['info', 'warning', 'critical'];

    /**
     * @param array<string, mixed> $toolInput
     */
    public function toReportExplanation(array $toolInput): OnDemandExplanationResult
    {
        return new OnDemandExplanationResult(
            $this->cleanText($toolInput['explanation'] ?? null, self::MAX_NARRATION, 'No explanation could be generated for this report.'),
        );
    }

    /**
     * @param array<string, mixed> $toolInput
     */
    public function toAnomaly(array $toolInput): AnomalyExplanationResult
    {
        $severity = is_string($toolInput['severity'] ?? null) ? $toolInput['severity'] : self::SEVERITY_FALLBACK;
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = self::SEVERITY_FALLBACK;
        }

        return new AnomalyExplanationResult(
            explanation: $this->cleanText($toolInput['explanation'] ?? null, self::MAX_NARRATION, 'An anomaly was detected in this report.'),
            severity: $severity,
            recommendedAction: $this->cleanText($toolInput['recommended_action'] ?? null, self::MAX_RECOMMENDATION, 'Review this report in your dashboard.'),
        );
    }

    /**
     * @param array<string, mixed> $toolInput
     */
    public function toWeeklyDigest(array $toolInput): WeeklyDigestResult
    {
        $metrics = [];
        foreach ($this->arrayOf($toolInput, 'key_metrics') as $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $label = $this->cleanText($metric['label'] ?? null, self::MAX_SHORT, '');
            $value = $this->cleanText($metric['value'] ?? null, self::MAX_SHORT, '');
            if ('' !== $label && '' !== $value) {
                $metrics[] = new KeyMetric($label, $value);
            }
        }

        $recommendations = [];
        foreach ($this->arrayOf($toolInput, 'recommendations') as $recommendation) {
            $clean = $this->cleanText($recommendation, self::MAX_RECOMMENDATION, '');
            if ('' !== $clean) {
                $recommendations[] = $clean;
            }
        }

        return new WeeklyDigestResult(
            summaryMarkdown: $this->cleanText($toolInput['summary'] ?? null, self::MAX_NARRATION, 'Your weekly summary is being prepared.'),
            keyMetrics: $metrics,
            recommendations: $recommendations,
        );
    }

    /**
     * @param array<string, mixed>     $toolInput
     * @param list<SuggestedDnsRecord> $records   PHP-generated records — the model never supplies these
     */
    public function toRemediation(array $toolInput, array $records): RemediationResult
    {
        return new RemediationResult(
            instructionsMarkdown: $this->cleanText($toolInput['instructions'] ?? null, self::MAX_NARRATION, 'Follow the suggested DNS records to fix this.'),
            suggestedDnsRecords: $records,
        );
    }

    /**
     * @param array<string, mixed> $toolInput
     */
    public function toSenderLabel(array $toolInput): SenderLabelResult
    {
        $confidence = is_numeric($toolInput['confidence'] ?? null) ? (float) $toolInput['confidence'] : 0.0;

        return new SenderLabelResult(
            label: $this->cleanText($toolInput['label'] ?? null, self::MAX_SHORT, 'Unknown sender'),
            confidence: max(0.0, min(1.0, $confidence)),
        );
    }

    private function cleanText(mixed $value, int $maxLength, string $fallback): string
    {
        $text = is_string($value) ? $value : '';
        $text = strip_tags($text);
        // The prompts forbid links; strip any that slip through (defense in depth).
        $text = preg_replace('#\b(?:https?://|mailto:)\S+#i', '[link removed]', $text) ?? $text;
        // Drop control chars but keep tab + newline so paragraphs survive nl2br rendering.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)).'…';
        }

        return '' === $text ? $fallback : $text;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<mixed>
     */
    private function arrayOf(array $input, string $key): array
    {
        return isset($input[$key]) && is_array($input[$key]) ? $input[$key] : [];
    }
}
