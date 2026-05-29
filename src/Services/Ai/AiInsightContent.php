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
 * The single place that knows the JSON shape of a cached AI result. `encode()`
 * flattens any Result DTO to the array stored in `ai_insight.content`; the
 * type-specific decoders rebuild the concrete DTO on a cache hit (typed, so no
 * `assert`/cast is needed at the call site).
 */
final readonly class AiInsightContent
{
    /**
     * @return array<string, mixed>
     */
    public static function encode(object $result): array
    {
        return match (true) {
            $result instanceof OnDemandExplanationResult => ['explanation' => $result->explanation],
            $result instanceof AnomalyExplanationResult => [
                'explanation' => $result->explanation,
                'severity' => $result->severity,
                'recommendedAction' => $result->recommendedAction,
            ],
            $result instanceof WeeklyDigestResult => [
                'summaryMarkdown' => $result->summaryMarkdown,
                'keyMetrics' => array_map(static fn (KeyMetric $m): array => ['label' => $m->label, 'value' => $m->value], $result->keyMetrics),
                'recommendations' => $result->recommendations,
            ],
            $result instanceof RemediationResult => [
                'instructionsMarkdown' => $result->instructionsMarkdown,
                'suggestedDnsRecords' => array_map(
                    static fn (SuggestedDnsRecord $r): array => ['type' => $r->type, 'host' => $r->host, 'value' => $r->value],
                    $result->suggestedDnsRecords,
                ),
            ],
            $result instanceof SenderLabelResult => ['label' => $result->label, 'confidence' => $result->confidence],
            default => throw new \InvalidArgumentException('Cannot encode AI result of type '.$result::class),
        };
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function reportExplanation(array $content): OnDemandExplanationResult
    {
        return new OnDemandExplanationResult(self::string($content, 'explanation'));
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function anomaly(array $content): AnomalyExplanationResult
    {
        return new AnomalyExplanationResult(
            explanation: self::string($content, 'explanation'),
            severity: self::string($content, 'severity'),
            recommendedAction: self::string($content, 'recommendedAction'),
        );
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function weeklyDigest(array $content): WeeklyDigestResult
    {
        $metrics = [];
        foreach (self::list($content, 'keyMetrics') as $metric) {
            if (is_array($metric)) {
                $metrics[] = new KeyMetric(self::string($metric, 'label'), self::string($metric, 'value'));
            }
        }

        $recommendations = [];
        foreach (self::list($content, 'recommendations') as $recommendation) {
            if (is_string($recommendation)) {
                $recommendations[] = $recommendation;
            }
        }

        return new WeeklyDigestResult(
            summaryMarkdown: self::string($content, 'summaryMarkdown'),
            keyMetrics: $metrics,
            recommendations: $recommendations,
        );
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function remediation(array $content): RemediationResult
    {
        $records = [];
        foreach (self::list($content, 'suggestedDnsRecords') as $record) {
            if (is_array($record)) {
                $records[] = new SuggestedDnsRecord(self::string($record, 'type'), self::string($record, 'host'), self::string($record, 'value'));
            }
        }

        return new RemediationResult(self::string($content, 'instructionsMarkdown'), $records);
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function senderLabel(array $content): SenderLabelResult
    {
        $confidence = $content['confidence'] ?? 0.0;

        return new SenderLabelResult(
            label: self::string($content, 'label'),
            confidence: is_numeric($confidence) ? (float) $confidence : 0.0,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    private static function list(array $data, string $key): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
    }
}
