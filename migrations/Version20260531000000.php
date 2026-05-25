<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-159 — Founder contact-form persistence.
 *
 * Stores every submission to /about/contact as a DB-first audit trail.
 * Deliberately has NO team_id FK — this is a public surface for both
 * signed-in users and anonymous visitors, so it must never be touched
 * by the team-scoping convention used elsewhere in the schema.
 */
final class Version20260531000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create contact_inquiry table for TASK-159 founder contact form';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_inquiry (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            submitter_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_contact_inquiry_submitted_at ON contact_inquiry (submitted_at)');
        $this->addSql('COMMENT ON COLUMN contact_inquiry.submitted_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contact_inquiry');
    }
}
