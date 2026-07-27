<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\IngestionSourceStatusRepository;
use App\Results\IngestionIntakeHealthResult;
use App\Value\IngestionIntakeState;
use App\Value\IngestionSource;
use Psr\Clock\ClockInterface;

/**
 * Answers "can we vouch for our own ingestion right now?" for read paths.
 *
 * A read-only façade on purpose: rendering a dashboard must never create an
 * `ingestion_source_status` row, because a row conjured by a page view would be
 * evidence of nothing while looking exactly like evidence of something.
 *
 * The answer is memoised per request. The dashboard asks once per domain in the
 * attention list, and pipeline health is a property of the deployment, not of
 * the domain — re-querying per row would be pure overhead and could, mid-render,
 * report two different truths on one page.
 */
final class IngestionHealthReader
{
    private ?bool $centralInboxProvenHealthy = null;

    public function __construct(
        private readonly IngestionSourceStatusRepository $repository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * False also means "never proven", which is the state of a fresh
     * deployment. Callers must treat false as "we are not entitled to blame the
     * user", never as "the pipeline is broken".
     */
    public function isCentralInboxProvenHealthy(): bool
    {
        if (null !== $this->centralInboxProvenHealthy) {
            return $this->centralInboxProvenHealthy;
        }

        $status = $this->repository->find(IngestionSource::CentralInbox);

        return $this->centralInboxProvenHealthy = null !== $status
            && $status->isProvenHealthyAt($this->clock->now());
    }

    /**
     * The three-state answer for operator surfaces.
     *
     * {@see isCentralInboxProvenHealthy()} deliberately collapses this to a
     * bool, because the only question a customer-facing message may ask is "are
     * we entitled to blame the user" — and both non-healthy states answer no.
     * An operator needs the distinction the bool throws away.
     */
    public function centralInboxIntakeHealth(): IngestionIntakeHealthResult
    {
        $status = $this->repository->find(IngestionSource::CentralInbox);

        if (null === $status || null === $status->lastSuccessAt) {
            return new IngestionIntakeHealthResult(IngestionIntakeState::NeverPolled, null);
        }

        return new IngestionIntakeHealthResult(
            $status->isProvenHealthyAt($this->clock->now())
                ? IngestionIntakeState::Healthy
                : IngestionIntakeState::Stale,
            $status->lastSuccessAt,
        );
    }
}
