<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W7 — give BYO-mailbox envelopes a per-mailbox key space, because
 * `(source, message_id)` is a key shared by every tenant on the platform.
 *
 * `Message-ID` is a header chosen by whoever sent the mail. For the CENTRAL
 * inbox that is fine: one global mailbox, one namespace, and the index is the
 * backstop against two concurrent workers inserting the same message. But
 * `ReportSource::ByoMailbox` is the same enum value for every customer, so once
 * BYO polling started writing envelopes, that pair spanned all of them.
 *
 * The trigger needs no attacker. A domain publishing
 * `rua=mailto:reports@acme.example,mailto:dmarc@agency.example` — the owner and
 * their agency — has both inboxes receiving the same reporter message. Each
 * connects its own mailbox on its own team. One Message-ID, two tenants: the
 * second poll bound to the first tenant's envelope, mutating a row it does not
 * own and cross-linking a `dmarc_report` on one team's domain to another team's
 * envelope.
 *
 * WHY TWO PARTIAL INDEXES AND NOT ONE THREE-COLUMN INDEX. PostgreSQL treats
 * NULLs as distinct in a unique index, and every central-inbox row has a NULL
 * `mailbox_connection_id`. A plain `(source, message_id, mailbox_connection_id)`
 * index would therefore stop constraining the central inbox at all — trading a
 * cross-tenant bug for the silent loss of the guarantee that path already had.
 * (`NULLS NOT DISTINCT` would also work on PostgreSQL 15+, but partial indexes
 * say what they mean and work on every version a self-hoster might run.)
 *
 * SAFE ON EXISTING DATA, and not by inspection alone: the new pair of
 * constraints is strictly WEAKER than the one it replaces. Index A restricts
 * the old columns to a subset of the rows; index B keeps the same columns and
 * adds one, which can only ever permit more rows. So any dataset that satisfied
 * `uniq_envelope_source_msgid` satisfies both of these, and this migration
 * cannot fail on data that is already there. Independently: nothing in `src/`
 * wrote a `byo_mailbox` envelope before the change this accompanies, so the
 * affected subset is empty in production regardless.
 *
 * Written non-concurrently. `received_report_email` is purged daily by
 * `sendvery:reports:purge`, so the table is small and the brief write lock is
 * cheaper than the failure modes of `CREATE INDEX CONCURRENTLY` (which cannot
 * run inside the migration's transaction and can leave an invalid index behind).
 */
final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope the BYO-mailbox envelope uniqueness to its own mailbox connection, keeping the central inbox globally unique (W7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_envelope_source_msgid');

        // Central inbox (and any future connection-less source): one global
        // namespace, exactly as before.
        $this->addSql('CREATE UNIQUE INDEX uniq_envelope_global_source_msgid
            ON received_report_email (source, message_id)
            WHERE (mailbox_connection_id IS NULL)');

        // BYO mailboxes: the sender-supplied Message-ID is only unique within
        // the mailbox that received it.
        $this->addSql('CREATE UNIQUE INDEX uniq_envelope_mailbox_source_msgid
            ON received_report_email (source, message_id, mailbox_connection_id)
            WHERE (mailbox_connection_id IS NOT NULL)');
    }

    /**
     * Reverses cleanly in schema terms, but note it re-imposes a STRICTER
     * constraint than the one it replaces. If two tenants have by then recorded
     * the same reporter message — the very case this migration exists to
     * permit — the recreate will fail on the duplicate rather than silently
     * merge or delete anyone's row. That is the correct behaviour for a
     * rollback: refuse loudly rather than destroy a tenant's envelope to fit an
     * index. Resolve by removing the surplus row deliberately, or by staying on
     * this version.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_envelope_mailbox_source_msgid');
        $this->addSql('DROP INDEX uniq_envelope_global_source_msgid');
        $this->addSql('CREATE UNIQUE INDEX uniq_envelope_source_msgid ON received_report_email (source, message_id)');
    }
}
