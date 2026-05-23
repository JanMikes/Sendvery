<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-022 — Sender Inventory bulk + audit + notes.
 *
 * Adds audit columns (who/when last changed) and a free-form notes field
 * to `known_sender`. The `updated_by_user_id` FK uses ON DELETE SET NULL
 * so we keep the audit row when an actor user is later deleted — the
 * template falls back to "system" in that case.
 */
final class Version20260526000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at, notes, updated_by_user_id columns to known_sender for TASK-022';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE known_sender ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN known_sender.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE known_sender ADD notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE known_sender ADD updated_by_user_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_known_sender_updated_by_user ON known_sender (updated_by_user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE known_sender
                ADD CONSTRAINT fk_known_sender_updated_by_user FOREIGN KEY (updated_by_user_id) REFERENCES "user" (id)
                ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE known_sender DROP CONSTRAINT fk_known_sender_updated_by_user');
        $this->addSql('DROP INDEX idx_known_sender_updated_by_user');
        $this->addSql('ALTER TABLE known_sender DROP updated_by_user_id');
        $this->addSql('ALTER TABLE known_sender DROP notes');
        $this->addSql('ALTER TABLE known_sender DROP updated_at');
    }
}
