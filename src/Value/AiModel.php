<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The Claude models Sendvery runs at runtime. Bare alias IDs (no date suffix)
 * so we always get the latest snapshot of each tier.
 *
 * Tiering is deliberate: the deterministic analysis layer pre-computes every
 * fact, so the model only narrates. That keeps the heavy tiers unnecessary at
 * runtime — Opus stays available for env-level A/B overrides but is never the
 * default (see AiModelPolicy). The real frontier-model work is authoring these
 * prompts, which happens at build time, not per request.
 */
enum AiModel: string
{
    case Haiku = 'claude-haiku-4-5';
    case Sonnet = 'claude-sonnet-4-6';
    case Opus = 'claude-opus-4-8';

    /**
     * Output-token cap per tier. Narration is short by design; these caps stop
     * a runaway generation from inflating cost and keep latency predictable.
     */
    public function maxOutputTokens(): int
    {
        return match ($this) {
            self::Haiku => 700,
            self::Sonnet => 1200,
            self::Opus => 1500,
        };
    }
}
