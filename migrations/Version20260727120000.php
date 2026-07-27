<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-resolving alerts: adds `alert.resolved_at` so an alert whose underlying
 * problem was observed fixed can stop demanding attention while staying
 * visible as a record that the fix landed.
 *
 * Safe on existing data: the column is nullable with no default, so every
 * historical alert lands as "not resolved" — exactly the state they were in
 * before this feature existed. No row is rewritten and no data is dropped.
 * The two indexes back the new predicates (`resolved_at IS NULL` on the
 * unread/critical badge counts, and the per-domain/per-type lookup the
 * resolution handler runs on every DNS check).
 */
final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alert.resolved_at (nullable) plus the two indexes backing resolved-aware attention counts and DNS auto-resolution lookups';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_alert_team_unread_resolved ON alert (team_id, is_read, resolved_at)');
        $this->addSql('CREATE INDEX idx_alert_domain_type_resolved ON alert (monitored_domain_id, type, resolved_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_alert_domain_type_resolved');
        $this->addSql('DROP INDEX idx_alert_team_unread_resolved');
        $this->addSql('ALTER TABLE alert DROP resolved_at');
    }
}
