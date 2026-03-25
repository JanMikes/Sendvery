<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325800000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add known_sender, blacklist_check_result, and domain_health_snapshot tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE known_sender (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            source_ip VARCHAR(45) NOT NULL,
            hostname VARCHAR(255) DEFAULT NULL,
            organization VARCHAR(255) DEFAULT NULL,
            label VARCHAR(255) DEFAULT NULL,
            is_authorized BOOLEAN NOT NULL DEFAULT FALSE,
            first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            total_messages INT NOT NULL DEFAULT 0,
            pass_rate DOUBLE PRECISION NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN known_sender.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN known_sender.monitored_domain_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN known_sender.first_seen_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN known_sender.last_seen_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_known_sender_domain_ip ON known_sender (monitored_domain_id, source_ip)');
        $this->addSql('CREATE INDEX idx_known_sender_domain ON known_sender (monitored_domain_id)');
        $this->addSql('ALTER TABLE known_sender ADD CONSTRAINT fk_known_sender_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE blacklist_check_result (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            results JSON NOT NULL,
            is_listed BOOLEAN NOT NULL DEFAULT FALSE,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN blacklist_check_result.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blacklist_check_result.monitored_domain_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blacklist_check_result.checked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_blacklist_check_domain ON blacklist_check_result (monitored_domain_id)');
        $this->addSql('CREATE INDEX idx_blacklist_check_domain_ip ON blacklist_check_result (monitored_domain_id, ip_address)');
        $this->addSql('ALTER TABLE blacklist_check_result ADD CONSTRAINT fk_blacklist_check_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE domain_health_snapshot (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            grade VARCHAR(1) NOT NULL,
            score INT NOT NULL,
            spf_score INT NOT NULL,
            dkim_score INT NOT NULL,
            dmarc_score INT NOT NULL,
            mx_score INT NOT NULL,
            blacklist_score INT NOT NULL,
            checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            recommendations JSON NOT NULL DEFAULT \'[]\',
            share_hash VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN domain_health_snapshot.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN domain_health_snapshot.monitored_domain_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN domain_health_snapshot.checked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_health_snapshot_domain ON domain_health_snapshot (monitored_domain_id)');
        $this->addSql('CREATE INDEX idx_health_snapshot_domain_date ON domain_health_snapshot (monitored_domain_id, checked_at)');
        $this->addSql('ALTER TABLE domain_health_snapshot ADD CONSTRAINT fk_health_snapshot_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE known_sender');
        $this->addSql('DROP TABLE blacklist_check_result');
        $this->addSql('DROP TABLE domain_health_snapshot');
    }
}
