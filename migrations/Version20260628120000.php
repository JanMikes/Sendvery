<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-058 — Managed DMARC (CNAME) + auto-ramp. Adds the managed-DMARC state
 * columns to monitored_domain and the managed_dmarc_policy_change audit table.
 * All additive (NOT-NULL columns carry a DB default), so it is safe on existing
 * rows and honours "never delete user data".
 */
final class Version20260628120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Managed DMARC (CNAME + auto-ramp) columns to monitored_domain and the managed_dmarc_policy_change audit table (DEC-058)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE monitored_domain ADD dmarc_setup_mode VARCHAR(20) DEFAULT 'self_txt' NOT NULL");
        $this->addSql('ALTER TABLE monitored_domain ADD cloudflare_hosted_dmarc_record_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD managed_policy_p VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD managed_policy_sp VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD managed_policy_pct INT DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD auto_ramp_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD auto_ramp_stage VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD auto_ramp_scheduled_stage VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD auto_ramp_scheduled_advance_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD auto_ramp_paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD managed_dmarc_enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD cname_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD last_policy_change_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE monitored_domain ADD hosted_dmarc_teardown_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE TABLE managed_dmarc_policy_change (
            id UUID NOT NULL,
            monitored_domain_id UUID NOT NULL,
            team_id UUID NOT NULL,
            actor_user_id UUID DEFAULT NULL,
            source VARCHAR(20) NOT NULL,
            from_policy VARCHAR(40) DEFAULT NULL,
            to_policy VARCHAR(40) NOT NULL,
            reason TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_mdpc_domain FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domain (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_mdpc_domain ON managed_dmarc_policy_change (monitored_domain_id)');
        $this->addSql('CREATE INDEX idx_mdpc_team ON managed_dmarc_policy_change (team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE managed_dmarc_policy_change');
        $this->addSql('ALTER TABLE monitored_domain DROP dmarc_setup_mode');
        $this->addSql('ALTER TABLE monitored_domain DROP cloudflare_hosted_dmarc_record_id');
        $this->addSql('ALTER TABLE monitored_domain DROP managed_policy_p');
        $this->addSql('ALTER TABLE monitored_domain DROP managed_policy_sp');
        $this->addSql('ALTER TABLE monitored_domain DROP managed_policy_pct');
        $this->addSql('ALTER TABLE monitored_domain DROP auto_ramp_enabled');
        $this->addSql('ALTER TABLE monitored_domain DROP auto_ramp_stage');
        $this->addSql('ALTER TABLE monitored_domain DROP auto_ramp_scheduled_stage');
        $this->addSql('ALTER TABLE monitored_domain DROP auto_ramp_scheduled_advance_at');
        $this->addSql('ALTER TABLE monitored_domain DROP auto_ramp_paused_at');
        $this->addSql('ALTER TABLE monitored_domain DROP managed_dmarc_enabled_at');
        $this->addSql('ALTER TABLE monitored_domain DROP cname_verified_at');
        $this->addSql('ALTER TABLE monitored_domain DROP last_policy_change_at');
        $this->addSql('ALTER TABLE monitored_domain DROP hosted_dmarc_teardown_at');
    }
}
