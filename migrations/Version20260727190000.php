<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-060 WP-D — record which autonomous system announces a sending address.
 *
 * A PTR record lives in the reverse zone of an IP block, and every VPS provider
 * hands that field to the customer. An AS number comes from BGP and the RIR
 * that allocated it, so it is the one identity axis a renter cannot claim — and
 * it names hosts that publish no PTR at all, where the alternative is showing a
 * bare address.
 *
 * Three columns rather than two. `asn IS NULL` alone would mean both "this
 * address is announced by nobody" and "we have never looked", and those are not
 * the same fact — the same three-valued discipline `forward_confirmed` already
 * follows. `asn_resolved_at` carries "have we asked?", and its being NULL is
 * what makes an already-cached row due for exactly one re-resolution instead of
 * being frozen without an answer forever.
 *
 * Purely additive: three nullable columns and an index. No rewrite, no
 * backfill required for correctness — existing rows self-heal on their next
 * ingest — though `bin/console sendvery:senders:backfill-identities` fills them
 * in one pass.
 */
final class Version20260727190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sender_identity.asn, asn_organization and asn_resolved_at so a sending host can be identified by the network announcing it (DEC-060 WP-D)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity ADD asn INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sender_identity ADD asn_organization VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE sender_identity ADD asn_resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sender_identity_asn ON sender_identity (asn)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sender_identity_asn');
        $this->addSql('ALTER TABLE sender_identity DROP asn_resolved_at');
        $this->addSql('ALTER TABLE sender_identity DROP asn_organization');
        $this->addSql('ALTER TABLE sender_identity DROP asn');
    }
}
