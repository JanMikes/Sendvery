<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325700000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stripe_subscription_id and plan_warning_at columns to team table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD COLUMN stripe_subscription_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE team ADD COLUMN plan_warning_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN team.plan_warning_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP COLUMN stripe_subscription_id');
        $this->addSql('ALTER TABLE team DROP COLUMN plan_warning_at');
    }
}
