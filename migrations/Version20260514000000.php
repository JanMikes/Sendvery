<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create beta_access_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE beta_access_request (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            company VARCHAR(255) DEFAULT NULL,
            requested_plan VARCHAR(32) NOT NULL,
            domain_count INTEGER DEFAULT NULL,
            message TEXT DEFAULT NULL,
            source VARCHAR(100) NOT NULL,
            requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_beta_access_request_requested_at ON beta_access_request (requested_at)');
        $this->addSql('COMMENT ON COLUMN beta_access_request.requested_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE beta_access_request');
    }
}
