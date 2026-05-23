<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-006 — tool-result soft conversion (Email me this report) reuses the
 * BetaSignup table. The original uniqueness on `email` blocked a single user
 * from opting into multiple tool/source captures (e.g. an agency engineer
 * subscribing for both SPF and DKIM alerts on different domains). Swap the
 * constraint to `(email, source)` so the same address can hold one row per
 * source slug while still preventing accidental double-submits from the same
 * micro-form.
 */
final class Version20260528000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relax beta_signup email uniqueness to (email, source) for TASK-006';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_beta_signup_email');
        $this->addSql('CREATE UNIQUE INDEX uniq_beta_signup_email_source ON beta_signup (email, source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_beta_signup_email_source');
        $this->addSql('CREATE UNIQUE INDEX uniq_beta_signup_email ON beta_signup (email)');
    }
}
