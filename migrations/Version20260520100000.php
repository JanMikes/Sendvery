<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add onboarding_team_completed_at to user for resumable onboarding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD onboarding_team_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "user".onboarding_team_completed_at IS \'(DC2Type:datetime_immutable)\'');

        // Backfill: users who already finished onboarding obviously completed the team step.
        $this->addSql('UPDATE "user" SET onboarding_team_completed_at = onboarding_completed_at WHERE onboarding_completed_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN onboarding_team_completed_at');
    }
}
