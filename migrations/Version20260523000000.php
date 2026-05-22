<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add team_invitation table for self-serve teammate invites';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE team_invitation (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                invited_by_id UUID NOT NULL,
                invited_email VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL,
                invitation_token VARCHAR(128) NOT NULL,
                status VARCHAR(20) NOT NULL,
                sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_team_invitation_token ON team_invitation (invitation_token)');
        $this->addSql('CREATE INDEX idx_team_invitation_team_status ON team_invitation (team_id, status)');
        $this->addSql('COMMENT ON COLUMN team_invitation.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN team_invitation.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN team_invitation.accepted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN team_invitation.revoked_at IS \'(DC2Type:datetime_immutable)\'');

        // Block duplicate active invites for the same (team, email) — admins must
        // revoke or expire the current one before sending a new one.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_team_invitation_active
              ON team_invitation (team_id, invited_email)
              WHERE status = 'pending'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_invitation
                ADD CONSTRAINT fk_team_invitation_team
                FOREIGN KEY (team_id) REFERENCES team (id)
                ON DELETE CASCADE
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE team_invitation
                ADD CONSTRAINT fk_team_invitation_invited_by
                FOREIGN KEY (invited_by_id) REFERENCES "user" (id)
                ON DELETE CASCADE
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_invitation');
    }
}
