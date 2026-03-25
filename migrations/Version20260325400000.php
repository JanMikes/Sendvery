<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325400000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create magic_link_token table and add onboarding_completed_at to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magic_link_token (
            id UUID NOT NULL,
            user_id UUID DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(128) NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_magic_link_token_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_magic_link_token ON magic_link_token (token)');
        $this->addSql('CREATE INDEX idx_magic_link_token_email ON magic_link_token (email)');
        $this->addSql('CREATE INDEX idx_magic_link_token_expires ON magic_link_token (expires_at)');
        $this->addSql('COMMENT ON COLUMN magic_link_token.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN magic_link_token.used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN magic_link_token.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE "user" ADD onboarding_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "user".onboarding_completed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE magic_link_token');
        $this->addSql('ALTER TABLE "user" DROP COLUMN onboarding_completed_at');
    }
}
