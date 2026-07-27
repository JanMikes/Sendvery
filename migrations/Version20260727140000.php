<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEC-059 — records whether a cached PTR hostname survived forward-confirmed
 * reverse DNS.
 *
 * A PTR record is published by whoever controls the reverse zone of an IP
 * block, which for most VPS customers is themselves. Sendvery grants the
 * `forwarder` role — and with it silence on the new-sender alert — on the
 * strength of that hostname, so an unconfirmed PTR was a free way to switch off
 * the signal that exists to surface spoofing. Forward confirmation resolves the
 * claimed hostname and requires the original address back in its RRset, which
 * no attacker can arrange for someone else's domain.
 *
 * Purely additive: one nullable column, no backfill, no rewrite of existing
 * rows.
 *
 * The column is deliberately nullable with **no** default of true or false.
 * NULL means "never asked", which is the honest state of every row cached
 * before this check existed. Defaulting to true would silently grandfather the
 * hole in for every already-cached address; defaulting to false would record a
 * verdict that was never reached, permanently demoting genuine forwarders.
 * NULL grants no trust and makes the row due for exactly one re-resolution, so
 * each cached host earns its answer on the next report that mentions it.
 */
final class Version20260727140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sender_identity.forward_confirmed so forwarder trust requires forward-confirmed reverse DNS, with NULL meaning "never checked" for rows cached before the check existed (DEC-059)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity ADD forward_confirmed BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sender_identity DROP forward_confirmed');
    }
}
