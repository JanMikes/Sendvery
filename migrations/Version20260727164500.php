<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-059 follow-up — persist RFC 7489 §6.7 policy-override reasons.
 *
 * `<policy_evaluated><reason>` is the receiver's own explanation for not
 * applying the published policy. Unlike a PTR record it is receiver-attested
 * and cannot be asserted by the sender, which makes it the strongest forwarder
 * evidence short of a DKIM signature surviving the hop. The parser has been
 * discarding it since day one.
 *
 * Stored as JSON, not a child table: a record may carry several reasons, but
 * nothing queries them, they are only ever read with their parent record, and
 * a join table would add N inserts per report to the ingest path for
 * filterability nobody has asked for.
 *
 * Purely additive and safe on live data — PostgreSQL 11+ adds a NOT NULL column
 * with a constant default as a catalog-only change, with no table rewrite.
 * `[]` is the honest value for the rows already stored: none of the DMARC
 * reports ingested so far carries a `<reason>` element, and the compressed raw
 * XML is retained per report, so a backfill remains possible if that changes.
 */
final class Version20260727164500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dmarc_record.policy_override_reasons so receiver-attested RFC 7489 policy-override reasons stop being discarded at parse time (DEC-059 follow-up)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE dmarc_record ADD policy_override_reasons JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dmarc_record DROP policy_override_reasons');
    }
}
