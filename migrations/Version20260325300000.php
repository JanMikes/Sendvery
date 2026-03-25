<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mailbox_connection table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mailbox_connection (
            id UUID NOT NULL,
            team_id UUID NOT NULL,
            monitored_domain_id UUID DEFAULT NULL,
            type VARCHAR(255) NOT NULL,
            host VARCHAR(255) NOT NULL,
            port INTEGER NOT NULL,
            encrypted_username TEXT NOT NULL,
            encrypted_password TEXT NOT NULL,
            encryption VARCHAR(255) NOT NULL,
            last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_mailbox_connection_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE,
            CONSTRAINT fk_mailbox_connection_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE SET NULL
        )');
        $this->addSql('CREATE INDEX idx_mailbox_connection_team ON mailbox_connection (team_id)');
        $this->addSql('CREATE INDEX idx_mailbox_connection_active ON mailbox_connection (is_active)');
        $this->addSql('COMMENT ON COLUMN mailbox_connection.last_polled_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN mailbox_connection.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mailbox_connection');
    }
}
