<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-146 — Per-domain DKIM selector preference.
 *
 * Adds a nullable `dkim_selector` column on monitored_domain so teams whose
 * DKIM selector isn't in DkimSelectorRegistry::PROVIDER_SELECTORS can teach
 * the dashboard the right selector instead of silently seeing "DKIM not
 * found" forever. NULL preserves the existing brute-force fallback; any
 * value is passed to DkimChecker::check() directly.
 */
final class Version20260530000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add monitored_domain.dkim_selector column for TASK-146';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain ADD dkim_selector VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain DROP dkim_selector');
    }
}
