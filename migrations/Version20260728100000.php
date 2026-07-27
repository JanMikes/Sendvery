<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W2 — record when a shared ingestion pipeline last provably worked.
 *
 * Nothing persisted the outcome of the central inbox poll, so the product could
 * not tell "this customer's rua= tag is wrong" from "our own poller is stuck".
 * Every surface resolved that ambiguity against the customer.
 *
 * One row per source, upserted in place: current state, not history. No purge
 * job is needed and none should be added — the table's size is bounded by the
 * number of IngestionSource cases.
 *
 * Only a success timestamp is stored. The poll runs inside a Messenger
 * transaction, so a throw rolls back any failure detail written on the way out;
 * staleness of this column is rollback-proof because it is the absence of a
 * write. See the entity docblock.
 *
 * Purely additive: a new table, referenced by nothing existing. Until the first
 * poll writes a row the table is empty, and callers must read that as
 * "unproven", never as "broken" — it is the state of every deployment for the
 * first five minutes after this ships.
 */
final class Version20260728100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ingestion_source_status so pipeline health is durable and distinguishable from a customer DNS fault (W2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingestion_source_status (
            id UUID NOT NULL,
            source VARCHAR(50) NOT NULL,
            last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN ingestion_source_status.id IS \'(DC2Type:uuid)\'');

        // One row per source is the whole contract: the recorder upserts, and a
        // duplicate would let two pollers disagree about whether we are healthy.
        $this->addSql('CREATE UNIQUE INDEX uniq_ingestion_source ON ingestion_source_status (source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingestion_source_status');
    }
}
