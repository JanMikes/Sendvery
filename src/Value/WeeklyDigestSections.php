<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Which {@see WeeklyDigestSection}s this week's digest actually contains,
 * resolved once and handed to both renderers.
 *
 * Passed into the Twig template as `sections` so the HTML alternative and the
 * plain-text alternative are guarded by the same booleans rather than by two
 * hand-maintained sets of conditions.
 */
final readonly class WeeklyDigestSections
{
    /**
     * @param array<string, bool> $presence keyed by {@see WeeklyDigestSection} value
     */
    private function __construct(
        private array $presence,
    ) {
    }

    public static function of(WeeklyDigestData $digest, bool $hasAiSummary): self
    {
        $presence = [];

        foreach (WeeklyDigestSection::cases() as $section) {
            $presence[$section->value] = $section->isPresentIn($digest, $hasAiSummary);
        }

        return new self($presence);
    }

    /**
     * Accepts the raw string so Twig can write `sections.has('new_senders')`.
     * An unknown name raises \ValueError rather than quietly returning false —
     * a typo in a template guard would otherwise delete a whole section from
     * the email and nothing would say so.
     */
    public function has(WeeklyDigestSection|string $section): bool
    {
        $case = $section instanceof WeeklyDigestSection ? $section : WeeklyDigestSection::from($section);

        return $this->presence[$case->value];
    }
}
