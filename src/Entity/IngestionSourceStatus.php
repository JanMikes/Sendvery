<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\IngestionSource;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * When one shared ingestion pipeline last provably worked.
 *
 * WHY THIS EXISTS: nothing recorded whether the central inbox poll had ever
 * succeeded. That absence is what made a whole class of failure invisible from
 * inside the product — if reports stopped arriving for a domain there was no
 * way to tell "the customer's rua= tag is wrong" from "our poller has been dead
 * since Tuesday", so every surface assumed the former and told the customer to
 * fix DNS that was already correct.
 *
 * One row per source, upserted in place. Current state, not history: the only
 * question it answers is "did this work recently", which needs one timestamp,
 * and a growing table would need its own purge job.
 *
 * WHY SUCCESS ONLY, with no stored error string or failure counter: the poll
 * runs inside a Messenger transaction, so a throw rolls the whole unit of work
 * back — any failure detail written on the way out would disappear on precisely
 * the runs it was meant to describe. Failure is therefore detected by this
 * timestamp going stale, which is rollback-proof because it is the ABSENCE of a
 * write. Failure *detail* already has two owners that survive a rollback: the
 * `lily-cron-run` wrapper (ran-and-failed) and Sentry's monitor (missed run).
 * Columns that only sometimes hold the truth are worse than columns that do not
 * exist.
 *
 * A poll that connects and finds nothing IS a success. That is the whole point:
 * an empty inbox proves the pipeline works.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingestion_source_status')]
final class IngestionSourceStatus
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public UuidInterface $id;

    #[ORM\Column(type: 'string', length: 50, unique: true, enumType: IngestionSource::class)]
    public readonly IngestionSource $source;

    /**
     * Nullable because a source that has never once succeeded has not earned a
     * timestamp. Reading NULL as "stale since epoch" would invent a
     * measurement, and NULL is the state of every deployment until the first
     * poll lands.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastSuccessAt;

    public function __construct(
        UuidInterface $id,
        IngestionSource $source,
    ) {
        $this->id = $id;
        $this->source = $source;
        $this->lastSuccessAt = null;
    }

    public function recordSuccess(\DateTimeImmutable $at): void
    {
        $this->lastSuccessAt = $at;
    }

    /**
     * Healthy means "we hold proof this pipeline worked recently".
     *
     * A source that has never succeeded answers false — but callers must read
     * false as "we cannot vouch for our own side", NOT as "the pipeline is
     * broken". The difference decides whether we are entitled to tell a
     * customer their DNS is at fault, and on a fresh deployment the honest
     * answer is that we do not yet know.
     */
    public function isProvenHealthyAt(\DateTimeImmutable $now): bool
    {
        if (null === $this->lastSuccessAt) {
            return false;
        }

        return $this->lastSuccessAt > $now->sub($this->source->stalenessThreshold());
    }
}
