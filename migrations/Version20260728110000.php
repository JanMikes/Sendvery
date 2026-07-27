<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W1 / DEC-062 (D2) — stop every stored snapshot claiming a clean blacklist it
 * never earned, and re-grade the history that claim inflated.
 *
 * `domain_health_snapshot.blacklist_score` was NOT NULL and every writer passed
 * the default 100, because nothing in the product ever dispatched
 * `CheckBlacklist`. That fabricated 100 carried 20% of the weighted grade —
 * enough to move an F-grade domain to a D — on a number published to an
 * unauthenticated share page, a PDF export and the REST API.
 *
 * TWO STEPS, AND THE SECOND INVENTS NOTHING. The column becomes nullable so
 * "not measured" is expressible. Then `score` and `grade` are recomputed from
 * the four per-category columns each row ALREADY stores, renormalised over the
 * 0.80 of weight those four represent. No new data is read and no value is
 * estimated: it is the same arithmetic the scorer now does at write time,
 * applied to numbers already on disk.
 *
 * WHY BACKFILL RATHER THAN LEAVE HISTORY ALONE. These rows are a time series
 * that trend charts plot. Fixing the formula only going forward would put a
 * cliff in every domain's chart on deploy day, and a reader would have no way
 * to tell that discontinuity from a real change in their mail. Recomputing is
 * the option that keeps the series comparable to itself.
 *
 * SAFETY: only rows belonging to domains with NO `blacklist_check_result` are
 * touched. Today that is every row — the table is empty — but scoping it this
 * way means the migration cannot corrupt a genuinely measured score if it is
 * ever replayed after the checker has started running.
 *
 * Grades are lower after this for every imperfect domain. That is the point:
 * they are the grades those domains always had on the evidence actually
 * collected.
 */
final class Version20260728110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make domain_health_snapshot.blacklist_score nullable and re-grade history that banked an unearned 100 (W1/D2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domain_health_snapshot ALTER blacklist_score DROP NOT NULL');

        $this->addSql('UPDATE domain_health_snapshot
            SET blacklist_score = NULL
            WHERE monitored_domain_id NOT IN (
                SELECT DISTINCT monitored_domain_id FROM blacklist_check_result
            )');

        // Renormalise over the four genuinely measured categories (0.80 of the
        // original weight), then re-derive the letter from the new number using
        // the same thresholds as DomainHealthScorer.
        $this->addSql('UPDATE domain_health_snapshot
            SET score = ROUND(
                    (dmarc_score * 0.25 + spf_score * 0.20 + dkim_score * 0.20 + mx_score * 0.15) / 0.80
                )
            WHERE blacklist_score IS NULL');

        $this->addSql("UPDATE domain_health_snapshot
            SET grade = CASE
                WHEN score >= 90 THEN 'A'
                WHEN score >= 75 THEN 'B'
                WHEN score >= 55 THEN 'C'
                WHEN score >= 35 THEN 'D'
                ELSE 'F'
            END
            WHERE blacklist_score IS NULL");
    }

    /**
     * Restores the previous shape: the fabricated 100 goes back and score/grade
     * are recomputed over all five weights. Reversing to the inflated numbers is
     * the honest `down()` — the NOT NULL constraint requires a value, and
     * pretending otherwise would leave a schema this app could not write to.
     *
     * RECOMPUTED, NOT RESTORED VERBATIM. Rows written by `HealthSnapshotComposer`
     * come back byte-identical, because the formula here is the one that produced
     * them. Rows whose score was set by hand — the demo seeder picks its grades
     * directly — come back formula-consistent instead of exactly as they were.
     * There is no way around that: the original score is not recoverable from the
     * columns that survive, and storing a copy to enable a rollback nobody expects
     * to run would be its own kind of fabrication.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE domain_health_snapshot SET blacklist_score = 100 WHERE blacklist_score IS NULL');

        $this->addSql('UPDATE domain_health_snapshot
            SET score = ROUND(
                dmarc_score * 0.25 + spf_score * 0.20 + dkim_score * 0.20 + mx_score * 0.15 + blacklist_score * 0.20
            )');

        $this->addSql("UPDATE domain_health_snapshot
            SET grade = CASE
                WHEN score >= 90 THEN 'A'
                WHEN score >= 75 THEN 'B'
                WHEN score >= 55 THEN 'C'
                WHEN score >= 35 THEN 'D'
                ELSE 'F'
            END");

        $this->addSql('ALTER TABLE domain_health_snapshot ALTER blacklist_score SET NOT NULL');
    }
}
