<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-015 — snooze + mute alerts.
 *
 * Adds the per-alert snooze deadline and the team-scoped mute table that
 * lets a user silence one (domain, alert-type) pair forward-only. Both
 * surfaces feed the single AlertEngine::createAlert chokepoint and the
 * alert list/count queries.
 */
final class Version20260525000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alert.snoozed_until column and muted_alert table for TASK-015';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD snoozed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN alert.snoozed_until IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_alert_team_unread_snoozed ON alert (team_id, is_read, snoozed_until)');

        $this->addSql(<<<'SQL'
            CREATE TABLE muted_alert (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                monitored_domain_id UUID NOT NULL,
                alert_type VARCHAR(64) NOT NULL,
                muted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN muted_alert.muted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_muted_alert_team ON muted_alert (team_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_muted_alert ON muted_alert (team_id, monitored_domain_id, alert_type)');
        $this->addSql(<<<'SQL'
            ALTER TABLE muted_alert
                ADD CONSTRAINT fk_muted_alert_team FOREIGN KEY (team_id) REFERENCES team (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE muted_alert
                ADD CONSTRAINT fk_muted_alert_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE muted_alert');
        $this->addSql('DROP INDEX idx_alert_team_unread_snoozed');
        $this->addSql('ALTER TABLE alert DROP snoozed_until');
    }
}
