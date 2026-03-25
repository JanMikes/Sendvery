<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create beta_signup table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE beta_signup (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            domain_count INTEGER DEFAULT NULL,
            pain_point TEXT DEFAULT NULL,
            source VARCHAR(100) NOT NULL,
            signed_up_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            confirmation_token VARCHAR(64) NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_beta_signup_email ON beta_signup (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_beta_signup_token ON beta_signup (confirmation_token)');
        $this->addSql('COMMENT ON COLUMN beta_signup.signed_up_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN beta_signup.confirmed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE beta_signup');
    }
}
