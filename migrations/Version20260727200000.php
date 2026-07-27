<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-060 WP-F — record whether dnswl.org lists a sending address.
 *
 * dnswl is a categorised whitelist of mail sources known not to send spam
 * (RFC 8904), and it lists forwarders and relaying MTAs heavily — which is
 * most of what "a legitimate mail source that is not the original sender"
 * means. The listing is decided by dnswl and not by the host, so a sender
 * cannot add itself.
 *
 * Corroboration only, which is why nothing here is indexed: no surface filters
 * on it, the classifier reads it alongside the row it already loaded, and an
 * index on a column with two distinct values across a small table would earn
 * nothing.
 *
 * Three-valued for the third time, and for the third identical reason:
 * `dnswl_trust_level IS NULL` would otherwise mean both "not listed" and "never
 * asked". `dnswl_checked_at` carries the question, and its being NULL is what
 * makes an already-cached row due for exactly one re-resolution.
 *
 * Purely additive. Existing rows self-heal on their next ingest;
 * `bin/console sendvery:senders:backfill-identities` fills them in one pass.
 */
final class Version20260727200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sender_identity.dnswl_trust_level, dnswl_category and dnswl_checked_at for RFC 8904 whitelist corroboration (DEC-060 WP-F)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity ADD dnswl_trust_level INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sender_identity ADD dnswl_category INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sender_identity ADD dnswl_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity DROP dnswl_checked_at');
        $this->addSql('ALTER TABLE sender_identity DROP dnswl_category');
        $this->addSql('ALTER TABLE sender_identity DROP dnswl_trust_level');
    }
}
