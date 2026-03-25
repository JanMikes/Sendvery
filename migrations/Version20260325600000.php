<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325600000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create beta_invitation and user_feedback tables, add email preferences to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD email_digest_enabled BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE "user" ADD email_alerts_enabled BOOLEAN NOT NULL DEFAULT true');

        $this->addSql('CREATE TABLE beta_invitation (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            invited_by_id UUID DEFAULT NULL,
            invitation_token VARCHAR(128) NOT NULL,
            status VARCHAR(20) NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_beta_invitation_user FOREIGN KEY (invited_by_id) REFERENCES "user" (id) ON DELETE SET NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_beta_invitation_token ON beta_invitation (invitation_token)');
        $this->addSql('CREATE INDEX idx_beta_invitation_email ON beta_invitation (email)');
        $this->addSql('CREATE INDEX idx_beta_invitation_status ON beta_invitation (status)');
        $this->addSql('COMMENT ON COLUMN beta_invitation.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN beta_invitation.accepted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN beta_invitation.expires_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE user_feedback (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            team_id UUID NOT NULL,
            type VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            page VARCHAR(512) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_user_feedback_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_feedback_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_user_feedback_team ON user_feedback (team_id)');
        $this->addSql('CREATE INDEX idx_user_feedback_created_at ON user_feedback (created_at)');
        $this->addSql('COMMENT ON COLUMN user_feedback.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_feedback');
        $this->addSql('DROP TABLE beta_invitation');
        $this->addSql('ALTER TABLE "user" DROP email_digest_enabled');
        $this->addSql('ALTER TABLE "user" DROP email_alerts_enabled');
    }
}
