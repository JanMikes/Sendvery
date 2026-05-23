<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-021 — onboarding setup checklist dismissal flag.
 *
 * Adds a team-scoped `setup_checklist_dismissed_at` column so a single member
 * hiding the checklist hides it for the whole team. Auto-un-dismiss on DMARC
 * regression is handled in the resolver (in-memory override) — the column is
 * never cleared, keeping the DNS-check hot path side-effect free.
 */
final class Version20260527000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add team.setup_checklist_dismissed_at column for TASK-021';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD setup_checklist_dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN team.setup_checklist_dismissed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP setup_checklist_dismissed_at');
    }
}
