<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quarantine table, partial unique index on verified domains, source pointer on dmarc_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quarantined_dmarc_report (
                id UUID NOT NULL,
                received_email_id UUID NOT NULL,
                domain_name TEXT NOT NULL,
                external_report_id TEXT NOT NULL,
                reporter_org TEXT NOT NULL,
                reporter_email TEXT NOT NULL,
                date_range_begin TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                date_range_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                quarantined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reason VARCHAR(32) NOT NULL,
                report_xml_gz BYTEA NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_quarantine_domain ON quarantined_dmarc_report (domain_name, quarantined_at)');
        $this->addSql('CREATE INDEX idx_quarantine_expires ON quarantined_dmarc_report (expires_at)');
        $this->addSql('COMMENT ON COLUMN quarantined_dmarc_report.date_range_begin IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN quarantined_dmarc_report.date_range_end IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN quarantined_dmarc_report.quarantined_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN quarantined_dmarc_report.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            ALTER TABLE quarantined_dmarc_report
                ADD CONSTRAINT fk_quarantine_received_email
                FOREIGN KEY (received_email_id) REFERENCES received_report_email (id)
                ON DELETE CASCADE
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // Hard-enforce "only one team can claim a domain once verified".
        // The controller checks this upfront, but the index is the safety net for races.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_verified_domain
              ON monitored_domain (LOWER(domain))
              WHERE dmarc_verified_at IS NOT NULL
        SQL);

        // Debug breadcrumb: trace every parsed report back to the email it came in on.
        $this->addSql('ALTER TABLE dmarc_report ADD source_envelope_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_dmarc_report_source_envelope ON dmarc_report (source_envelope_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE dmarc_report
                ADD CONSTRAINT fk_dmarc_report_source_envelope
                FOREIGN KEY (source_envelope_id) REFERENCES received_report_email (id)
                ON DELETE SET NULL
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dmarc_report DROP CONSTRAINT IF EXISTS fk_dmarc_report_source_envelope');
        $this->addSql('DROP INDEX IF EXISTS idx_dmarc_report_source_envelope');
        $this->addSql('ALTER TABLE dmarc_report DROP COLUMN source_envelope_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_verified_domain');
        $this->addSql('DROP TABLE quarantined_dmarc_report');
    }
}
