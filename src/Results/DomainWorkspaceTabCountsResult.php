<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Per-tab attention counters for the domain workspace tab strip
 * (TASK-084). Produced in a single SQL round-trip by
 * {@see \App\Query\GetDomainWorkspaceTabCounts::forDomain()} and forwarded
 * into the `<twig:DomainWorkspaceTabs>` component via {@see toTwigArray()}.
 *
 * Semantic split between count tabs (`reports`, `unauthorizedSenders`,
 * `blacklistListed`) and "1 = something to look at" boolean tabs
 * (`dnsFailing`, `historyChanged7d`) is preserved so the template can pick
 * the right glyph (number badge vs. tiny dot).
 */
final readonly class DomainWorkspaceTabCountsResult
{
    public function __construct(
        public int $reports24h,
        public int $unauthorizedSenders,
        public bool $dnsFailing,
        public int $blacklistListed,
        public bool $historyChanged7d,
    ) {
    }

    /**
     * @param array{
     *     reports_24h: int|string|null,
     *     unauthorized_senders: int|string|null,
     *     dns_failing: int|string|bool|null,
     *     blacklist_listed: int|string|null,
     *     history_changed_7d: int|string|bool|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            reports24h: (int) ($row['reports_24h'] ?? 0),
            unauthorizedSenders: (int) ($row['unauthorized_senders'] ?? 0),
            dnsFailing: self::toBool($row['dns_failing'] ?? false),
            blacklistListed: (int) ($row['blacklist_listed'] ?? 0),
            historyChanged7d: self::toBool($row['history_changed_7d'] ?? false),
        );
    }

    /**
     * Twig-facing view of the counters. Keys match the workspace tab keys
     * (`reports`, `senders`, `dns`, `blacklist`, `history`, `overview`).
     *
     * Zero / false collapses to `null` so the template's
     * `{% if tabCounts.reports %}` check naturally suppresses badges
     * without needing an explicit `> 0` guard per tab. `overview` is
     * always `null` per the TASK-084 spec — overview is the catch-all
     * surface and never carries a badge.
     *
     * @return array{
     *     reports: int|null,
     *     senders: int|null,
     *     dns: int|null,
     *     blacklist: int|null,
     *     history: int|null,
     *     overview: null
     * }
     */
    public function toTwigArray(): array
    {
        return [
            'reports' => $this->reports24h > 0 ? $this->reports24h : null,
            'senders' => $this->unauthorizedSenders > 0 ? $this->unauthorizedSenders : null,
            'dns' => $this->dnsFailing ? 1 : null,
            'blacklist' => $this->blacklistListed > 0 ? $this->blacklistListed : null,
            'history' => $this->historyChanged7d ? 1 : null,
            'overview' => null,
        ];
    }

    private static function toBool(int|string|bool|null $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (null === $value) {
            return false;
        }

        return 0 !== (int) $value;
    }
}
