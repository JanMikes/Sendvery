<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_insight table for caching AI-generated insights (DEC-057 real AnthropicAiInsightsService)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_insight (
            id UUID NOT NULL,
            team_id UUID DEFAULT NULL,
            type VARCHAR(255) NOT NULL,
            subject_id VARCHAR(64) NOT NULL,
            cache_key VARCHAR(255) NOT NULL,
            content JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_ai_insight_team FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_insight_cache_key ON ai_insight (cache_key)');
        $this->addSql('CREATE INDEX idx_ai_insight_team ON ai_insight (team_id)');
        $this->addSql('CREATE INDEX idx_ai_insight_type_subject ON ai_insight (type, subject_id)');
        $this->addSql('COMMENT ON COLUMN ai_insight.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_insight');
    }
}
