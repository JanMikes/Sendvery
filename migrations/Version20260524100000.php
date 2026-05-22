<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add billing_interval to team (monthly | annual). Null for Free/Unlimited
 * and for teams that haven't subscribed yet. Required by the new pricing
 * model (DEC-054) where annual = exactly 2 months free.
 */
final class Version20260524100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing_interval column to team';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD billing_interval VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP billing_interval');
    }
}
