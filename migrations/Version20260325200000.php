<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create monitored_domain, dmarc_report, and dmarc_record tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monitored_domain (
            id UUID NOT NULL,
            team_id UUID NOT NULL,
            domain VARCHAR(255) NOT NULL,
            dmarc_policy VARCHAR(255) DEFAULT NULL,
            is_verified BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_monitored_domain_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_monitored_domain_team_domain ON monitored_domain (team_id, domain)');
        $this->addSql('CREATE INDEX idx_monitored_domain_team ON monitored_domain (team_id)');
        $this->addSql('COMMENT ON COLUMN monitored_domain.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE dmarc_report (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            reporter_org VARCHAR(255) NOT NULL,
            reporter_email VARCHAR(255) NOT NULL,
            external_report_id VARCHAR(255) NOT NULL,
            date_range_begin TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            date_range_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            policy_domain VARCHAR(255) NOT NULL,
            policy_adkim VARCHAR(255) NOT NULL,
            policy_aspf VARCHAR(255) NOT NULL,
            policy_p VARCHAR(255) NOT NULL,
            policy_sp VARCHAR(255) DEFAULT NULL,
            policy_pct INTEGER NOT NULL,
            raw_xml TEXT NOT NULL,
            processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_dmarc_report_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_dmarc_report_domain_external_id ON dmarc_report (monitored_domain_id, external_report_id)');
        $this->addSql('CREATE INDEX idx_dmarc_report_domain ON dmarc_report (monitored_domain_id)');
        $this->addSql('CREATE INDEX idx_dmarc_report_date_range ON dmarc_report (date_range_end)');
        $this->addSql('COMMENT ON COLUMN dmarc_report.date_range_begin IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN dmarc_report.date_range_end IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN dmarc_report.processed_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE dmarc_record (
            id UUID NOT NULL,
            dmarc_report_id UUID NOT NULL,
            source_ip VARCHAR(45) NOT NULL,
            count INTEGER NOT NULL,
            disposition VARCHAR(255) NOT NULL,
            dkim_result VARCHAR(255) NOT NULL,
            spf_result VARCHAR(255) NOT NULL,
            header_from VARCHAR(255) NOT NULL,
            dkim_domain VARCHAR(255) DEFAULT NULL,
            dkim_selector VARCHAR(255) DEFAULT NULL,
            spf_domain VARCHAR(255) DEFAULT NULL,
            resolved_hostname VARCHAR(255) DEFAULT NULL,
            resolved_org VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_dmarc_record_report FOREIGN KEY (dmarc_report_id) REFERENCES dmarc_report (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_dmarc_record_report ON dmarc_record (dmarc_report_id)');
        $this->addSql('CREATE INDEX idx_dmarc_record_source_ip ON dmarc_record (source_ip)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dmarc_record');
        $this->addSql('DROP TABLE dmarc_report');
        $this->addSql('DROP TABLE monitored_domain');
    }
}
