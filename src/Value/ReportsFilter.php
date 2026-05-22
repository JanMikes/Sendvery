<?php

declare(strict_types=1);

namespace App\Value;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * URL-driven filter state for the reports list pages. Parsed from the
 * incoming request once per page render, then carried through to both
 * the query layer (named args on GetAllReports::forTeams) and the Twig
 * filter bar component. Pure value object — no side effects, no DB.
 */
final readonly class ReportsFilter
{
    /**
     * @param list<string> $domainIds
     * @param list<string> $reporterOrgs
     */
    public function __construct(
        public array $domainIds,
        public array $reporterOrgs,
        public ?string $passRateBand,
        public ?string $dateRange,
        public ?\DateTimeImmutable $dateFrom,
        public ?\DateTimeImmutable $dateTo,
        public ?string $search,
    ) {
    }

    public static function fromRequest(Request $request, ClockInterface $clock): self
    {
        $rawDomainIds = $request->query->all('domain');
        $domainIds = [];
        foreach ($rawDomainIds as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ('' === $trimmed) {
                continue;
            }
            if (!Uuid::isValid($trimmed)) {
                continue;
            }
            $domainIds[] = $trimmed;
        }

        $rawReporters = $request->query->all('reporter');
        $reporterOrgs = [];
        foreach ($rawReporters as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ('' === $trimmed) {
                continue;
            }
            $reporterOrgs[] = $trimmed;
        }

        $rawPassRate = $request->query->get('pass_rate');
        $passRateBand = is_string($rawPassRate) && in_array($rawPassRate, ['high', 'medium', 'low'], true)
            ? $rawPassRate
            : null;

        $rawDateRange = $request->query->get('date_range');
        $dateRange = is_string($rawDateRange) && in_array($rawDateRange, ['7d', '30d', '90d', 'custom'], true)
            ? $rawDateRange
            : null;

        $dateFrom = null;
        $dateTo = null;

        if (in_array($dateRange, ['7d', '30d', '90d'], true)) {
            $days = (int) substr($dateRange, 0, -1);
            $dateFrom = $clock->now()->modify(sprintf('-%d days', $days));
            if (!$dateFrom instanceof \DateTimeImmutable) {
                $dateFrom = null;
            }
        } elseif ('custom' === $dateRange) {
            $rawFrom = $request->query->get('date_from');
            $rawTo = $request->query->get('date_to');

            if (is_string($rawFrom) && '' !== trim($rawFrom)) {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($rawFrom));
                $dateFrom = false !== $parsed ? $parsed : null;
            }
            if (is_string($rawTo) && '' !== trim($rawTo)) {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($rawTo));
                $dateTo = false !== $parsed ? $parsed : null;
            }

            // Swap if reversed — keep the filter usable rather than silently
            // returning empty for an honest mistake.
            if (null !== $dateFrom && null !== $dateTo && $dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }
        }

        $rawSearch = $request->query->get('q');
        $search = null;
        if (is_string($rawSearch)) {
            $trimmed = trim($rawSearch);
            $search = '' === $trimmed ? null : $trimmed;
        }

        return new self(
            domainIds: $domainIds,
            reporterOrgs: $reporterOrgs,
            passRateBand: $passRateBand,
            dateRange: $dateRange,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            search: $search,
        );
    }

    /**
     * URL query parameters reflecting the current state — used as the
     * base for pagination links and chip-bar URLs so filters survive
     * navigation. Only non-empty values are emitted to keep URLs clean.
     *
     * @return array<string, mixed>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ([] !== $this->domainIds) {
            $params['domain'] = $this->domainIds;
        }

        if ([] !== $this->reporterOrgs) {
            $params['reporter'] = $this->reporterOrgs;
        }

        if (null !== $this->passRateBand) {
            $params['pass_rate'] = $this->passRateBand;
        }

        if (null !== $this->dateRange) {
            $params['date_range'] = $this->dateRange;
        }

        if ('custom' === $this->dateRange) {
            if (null !== $this->dateFrom) {
                $params['date_from'] = $this->dateFrom->format('Y-m-d');
            }
            if (null !== $this->dateTo) {
                $params['date_to'] = $this->dateTo->format('Y-m-d');
            }
        }

        if (null !== $this->search) {
            $params['q'] = $this->search;
        }

        return $params;
    }

    public function hasActiveFilters(): bool
    {
        return [] !== $this->domainIds
            || [] !== $this->reporterOrgs
            || null !== $this->passRateBand
            || null !== $this->dateRange
            || null !== $this->search;
    }

    public function passRateMin(): ?float
    {
        return match ($this->passRateBand) {
            'high' => 90.0,
            'medium' => 70.0,
            default => null,
        };
    }

    public function passRateMax(): ?float
    {
        return match ($this->passRateBand) {
            'medium' => 89.99,
            'low' => 69.99,
            default => null,
        };
    }
}
