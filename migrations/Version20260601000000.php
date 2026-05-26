<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cloudflare_auth_record_id column to monitored_domain for RFC 7489 DNS automation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain ADD cloudflare_auth_record_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domain DROP cloudflare_auth_record_id');
    }
}
