<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325500000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create dns_check_result and alert tables for DNS monitoring and alerting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dns_check_result (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            type VARCHAR(255) NOT NULL,
            checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            raw_record TEXT DEFAULT NULL,
            is_valid BOOLEAN NOT NULL,
            issues JSON NOT NULL,
            details JSON NOT NULL,
            previous_raw_record TEXT DEFAULT NULL,
            has_changed BOOLEAN NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_dns_check_result_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_dns_check_domain_type ON dns_check_result (monitored_domain_id, type)');
        $this->addSql('CREATE INDEX idx_dns_check_checked_at ON dns_check_result (checked_at)');
        $this->addSql('COMMENT ON COLUMN dns_check_result.checked_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE alert (
            id UUID NOT NULL,
            team_id UUID NOT NULL,
            monitored_domain_id UUID DEFAULT NULL,
            type VARCHAR(255) NOT NULL,
            severity VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            data JSON NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT false,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_alert_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE,
            CONSTRAINT fk_alert_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE SET NULL
        )');
        $this->addSql('CREATE INDEX idx_alert_team ON alert (team_id)');
        $this->addSql('CREATE INDEX idx_alert_team_unread ON alert (team_id, is_read)');
        $this->addSql('CREATE INDEX idx_alert_created_at ON alert (created_at)');
        $this->addSql('COMMENT ON COLUMN alert.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert');
        $this->addSql('DROP TABLE dns_check_result');
    }
}
