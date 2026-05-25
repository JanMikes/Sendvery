<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-133 — Soft-delete column for mailbox disconnect.
 *
 * Adds `mailbox_connection.disconnected_at` so a user-initiated "Disconnect this
 * mailbox" action (from `MailboxHealthAdvisor`'s silent-for-too-long CTA or the
 * per-mailbox detail page) sets a timestamp instead of hard-deleting the row.
 * Aligns with the `never-delete-user-data` memory: existing retention rules
 * eventually purge the row. The repository filters disconnected rows out of
 * `findActiveConnections()` and `findByTeam()` so the cron poller and dashboard
 * list both skip them; the entity itself stays around for audit + late-arriving
 * report attribution.
 */
final class Version20260525125419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mailbox_connection.disconnected_at column for TASK-133';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox_connection ADD disconnected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN mailbox_connection.disconnected_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox_connection DROP disconnected_at');
    }
}
