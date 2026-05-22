<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Monthly counters for plan enforcement (DEC-053, DEC-055).
 *
 * - team_usage tracks parsed DMARC reports per team per monthly period
 *   (plan limit per `PlanLimits::getMaxReportsPerMonth`).
 * - team_ai_usage tracks on-demand AI explanations per team per monthly
 *   period (plan limit per `PlanLimits::getOnDemandAiQuota`).
 *
 * Both are reset to zero by the `sendvery:usage:reset` cron at the
 * start of each calendar month (compared via period_ends_at < now).
 */
final class Version20260524200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add team_usage and team_ai_usage tables for monthly plan-limit counters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE team_usage (
                team_id UUID NOT NULL,
                reports_parsed_count INT NOT NULL DEFAULT 0,
                period_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(team_id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN team_usage.period_started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN team_usage.period_ends_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            ALTER TABLE team_usage
                ADD CONSTRAINT fk_team_usage_team FOREIGN KEY (team_id) REFERENCES team (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE team_ai_usage (
                team_id UUID NOT NULL,
                on_demand_count INT NOT NULL DEFAULT 0,
                period_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(team_id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN team_ai_usage.period_started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN team_ai_usage.period_ends_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            ALTER TABLE team_ai_usage
                ADD CONSTRAINT fk_team_ai_usage_team FOREIGN KEY (team_id) REFERENCES team (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_ai_usage');
        $this->addSql('DROP TABLE team_usage');
    }
}
