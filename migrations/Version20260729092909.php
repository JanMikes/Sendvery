<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729092909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record origin IP and User-Agent on magic-link tokens (signup-abuse forensics)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_link_token ADD requested_ip VARCHAR(45) DEFAULT NULL');
        $this->addSql('ALTER TABLE magic_link_token ADD requested_user_agent VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_link_token DROP requested_ip');
        $this->addSql('ALTER TABLE magic_link_token DROP requested_user_agent');
    }
}
