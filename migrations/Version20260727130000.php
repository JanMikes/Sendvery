<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-059 — Sender identity core. Adds the `sender_identity` table: a global,
 * IP-keyed cache of objective network facts (PTR hostname, registrable domain,
 * organisation, role) shared across every team.
 *
 * Purely additive — it creates one new table and touches nothing existing, so
 * it is safe on live data and cannot conflict with `known_sender`, which stays
 * the per-domain, user-owned record. The new table is a cache: it holds no
 * tenant data and no user input, so rebuilding it loses nothing.
 */
final class Version20260727130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the global sender_identity cache (PTR hostname, registrable domain, organisation, role) with its IP unique index and registrable-domain lookup index (DEC-059)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sender_identity (
            id UUID NOT NULL,
            source_ip VARCHAR(45) NOT NULL,
            hostname VARCHAR(255) DEFAULT NULL,
            registrable_domain VARCHAR(255) DEFAULT NULL,
            organization VARCHAR(255) DEFAULT NULL,
            role VARCHAR(20) NOT NULL,
            resolved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            resolution_attempts INT NOT NULL,
            last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        // The unique index is the cache contract: one row per IP, so a
        // concurrent second ingest of the same IP fails loudly instead of
        // silently duplicating identities.
        $this->addSql('CREATE UNIQUE INDEX uniq_sender_identity_source_ip ON sender_identity (source_ip)');
        // Senders are grouped by registrable domain, not by IP (DEC-059 §3.2),
        // so every read path filters or joins on this column.
        $this->addSql('CREATE INDEX idx_sender_identity_registrable_domain ON sender_identity (registrable_domain)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sender_identity');
    }
}
