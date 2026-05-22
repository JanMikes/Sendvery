<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switches monitored_domain to a system-wide unique constraint on the
 * domain name (case-insensitive) — only one team can own a domain at a time.
 * Adds the domain_ownership_inquiry table that backs the "I think a coworker
 * owns this, but I'm the real owner — notify admin" CTA on the taken page.
 */
final class Version20260523100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'System-wide unique domain + domain_ownership_inquiry table';
    }

    public function up(Schema $schema): void
    {
        // Old constraints made the same domain claimable by multiple teams as
        // long as none had verified, or only enforced uniqueness on the verified
        // subset. The new rule is stricter: at most one row per (lower) domain
        // name across the whole system, verified or not.
        $this->addSql('ALTER TABLE monitored_domain DROP CONSTRAINT IF EXISTS uniq_monitored_domain_team_domain');
        $this->addSql('DROP INDEX IF EXISTS uniq_monitored_domain_team_domain');
        $this->addSql('DROP INDEX IF EXISTS uniq_verified_domain');

        $this->addSql('CREATE UNIQUE INDEX uniq_monitored_domain_name ON monitored_domain (LOWER(domain))');

        $this->addSql(<<<'SQL'
            CREATE TABLE domain_ownership_inquiry (
                id UUID NOT NULL,
                domain TEXT NOT NULL,
                inquiring_user_id UUID NOT NULL,
                inquiring_team_id UUID NOT NULL,
                current_owner_team_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_inquiry_dedupe ON domain_ownership_inquiry (inquiring_user_id, domain, created_at)');
        $this->addSql('COMMENT ON COLUMN domain_ownership_inquiry.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN domain_ownership_inquiry.notified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            ALTER TABLE domain_ownership_inquiry
                ADD CONSTRAINT fk_inquiry_user FOREIGN KEY (inquiring_user_id) REFERENCES "user" (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE domain_ownership_inquiry
                ADD CONSTRAINT fk_inquiry_team FOREIGN KEY (inquiring_team_id) REFERENCES team (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE domain_ownership_inquiry
                ADD CONSTRAINT fk_inquiry_owner_team FOREIGN KEY (current_owner_team_id) REFERENCES team (id)
                ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE domain_ownership_inquiry');
        $this->addSql('DROP INDEX IF EXISTS uniq_monitored_domain_name');
        $this->addSql('CREATE UNIQUE INDEX uniq_monitored_domain_team_domain ON monitored_domain (team_id, domain)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_verified_domain
              ON monitored_domain (LOWER(domain))
              WHERE dmarc_verified_at IS NOT NULL
        SQL);
    }
}
