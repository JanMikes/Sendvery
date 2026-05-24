<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-091 — DNS-first next-step dismissal flag.
 *
 * Adds a team-scoped `ingestion_recommendation_dismissed_at` column so the
 * dashboard's "Publish a DMARC RUA record" next-step can be hidden for the
 * whole team after explicit user dismissal. Mirrors the
 * `setup_checklist_dismissed_at` shape from TASK-021.
 */
final class Version20260529000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add team.ingestion_recommendation_dismissed_at column for TASK-091';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD ingestion_recommendation_dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN team.ingestion_recommendation_dismissed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP ingestion_recommendation_dismissed_at');
    }
}
