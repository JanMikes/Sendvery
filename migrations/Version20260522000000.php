<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add received_report_email envelope table for central-inbox ingestion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE received_report_email (
                id UUID NOT NULL,
                mailbox_connection_id UUID DEFAULT NULL,
                source VARCHAR(32) NOT NULL,
                imap_uidvalidity BIGINT DEFAULT NULL,
                imap_uid BIGINT DEFAULT NULL,
                message_id TEXT NOT NULL,
                from_address TEXT NOT NULL,
                subject TEXT NOT NULL,
                received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ingested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                size_bytes INT NOT NULL,
                raw_eml BYTEA NOT NULL,
                processing_status VARCHAR(32) NOT NULL,
                processing_error TEXT DEFAULT NULL,
                attempts INT NOT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_envelope_source_msgid ON received_report_email (source, message_id)');
        $this->addSql('CREATE INDEX idx_envelope_status ON received_report_email (processing_status, ingested_at)');
        $this->addSql('CREATE INDEX idx_envelope_mailbox_connection ON received_report_email (mailbox_connection_id)');
        $this->addSql('COMMENT ON COLUMN received_report_email.received_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN received_report_email.ingested_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN received_report_email.processed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            ALTER TABLE received_report_email
                ADD CONSTRAINT fk_envelope_mailbox_connection
                FOREIGN KEY (mailbox_connection_id) REFERENCES mailbox_connection (id)
                ON DELETE SET NULL
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE received_report_email');
    }
}
