<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace monitored_domain.is_verified bool with per-record verification timestamps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain ADD spf_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD dkim_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD dmarc_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD first_report_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN monitored_domain.spf_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain.dkim_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain.dmarc_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain.first_report_at IS \'(DC2Type:datetime_immutable)\'');

        // is_verified was never written; drop it.
        $this->addSql('ALTER TABLE monitored_domain DROP COLUMN is_verified');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain ADD is_verified BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE monitored_domain DROP COLUMN spf_verified_at');
        $this->addSql('ALTER TABLE monitored_domain DROP COLUMN dkim_verified_at');
        $this->addSql('ALTER TABLE monitored_domain DROP COLUMN dmarc_verified_at');
        $this->addSql('ALTER TABLE monitored_domain DROP COLUMN first_report_at');
    }
}
