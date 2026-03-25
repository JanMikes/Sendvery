<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create team, user, and team_membership tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE team (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            stripe_customer_id VARCHAR(255) DEFAULT NULL,
            plan VARCHAR(50) NOT NULL DEFAULT \'free\',
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C4E0A61F989D9B62 ON team (slug)');
        $this->addSql('COMMENT ON COLUMN team.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE "user" (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            locale VARCHAR(10) NOT NULL DEFAULT \'en\',
            last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('COMMENT ON COLUMN "user".last_login_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE team_membership (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            team_id UUID NOT NULL,
            role VARCHAR(20) NOT NULL,
            joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_B826A042A76ED395 ON team_membership (user_id)');
        $this->addSql('CREATE INDEX IDX_B826A042296CD8AE ON team_membership (team_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_user_team ON team_membership (user_id, team_id)');
        $this->addSql('COMMENT ON COLUMN team_membership.joined_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE team_membership ADD CONSTRAINT FK_B826A042A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE team_membership ADD CONSTRAINT FK_B826A042296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_membership DROP CONSTRAINT FK_B826A042296CD8AE');
        $this->addSql('ALTER TABLE team_membership DROP CONSTRAINT FK_B826A042A76ED395');
        $this->addSql('DROP TABLE team_membership');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE team');
    }
}
