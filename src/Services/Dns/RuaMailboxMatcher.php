<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\MailboxConnection;
use App\Repository\MailboxConnectionRepository;
use App\Services\CredentialEncryptor;
use Ramsey\Uuid\Uuid;

/**
 * Shared "does the published rua= address point at a mailbox we're polling?"
 * resolver (TASK-114). Used by both {@see \App\Services\IngestionPathResolver}
 * (per-row matrix decoration on `/app/mailboxes`) and
 * {@see \App\Services\DomainSetupStatusResolver} (5th RUA destination row on
 * `/app/domains/{id}`).
 *
 * Lives as a sibling of {@see RuaScenarioResolver} but deliberately separate:
 * `RuaScenarioResolver` stays DNS-only (it answers "where does the published
 * record route reports?"); this matcher composes that DNS answer with the
 * mailbox connection inventory ("is one of those routes a mailbox we're
 * polling?"). Adding a `PointsAtConnectedMailbox` case to `RuaScenario` would
 * have leaked mailbox-side concerns into a DNS-only enum.
 *
 * The matching rule (intentionally tight — alias forwarding and plus-tagging
 * are out of scope for v1):
 *   1. Strip any `mailto:` prefix from both sides.
 *   2. Lower-case + trim both sides.
 *   3. Both sides must contain `@` (a non-email IMAP login like `u12345` can't
 *      meaningfully match an email address — bail).
 *   4. Exact local-part@domain equality wins; everything else loses.
 *
 * Returns false on any missing input — defensive so partial data never
 * accidentally flips the success-tone badge that consumes this signal.
 */
readonly class RuaMailboxMatcher
{
    public function __construct(
        private MailboxConnectionRepository $mailboxConnectionRepository,
        private CredentialEncryptor $credentialEncryptor,
    ) {
    }

    /**
     * True when the team has a connected mailbox bound to `$domainId` whose
     * decrypted login matches the supplied `$ruaEmail`. The caller is
     * responsible for whatever scenario-side preconditions apply (e.g.
     * `path = Mailbox`, `scenario = PointsAtExternal`) — this method ONLY
     * answers the matching question.
     */
    public function matchesConnectedMailbox(string $domainId, ?string $ruaEmail): bool
    {
        if (null === $ruaEmail || '' === trim($ruaEmail)) {
            return false;
        }

        $mailbox = $this->findMailboxForDomain($domainId);
        if (null === $mailbox) {
            return false;
        }

        try {
            $username = $this->credentialEncryptor->decrypt($mailbox->encryptedUsername);
        } catch (\RuntimeException) {
            // Decryption failure (corrupted ciphertext, rotated key, etc.)
            // shouldn't flip the badge — fall through to the conservative
            // scenario-warning rendering. The underlying error surfaces
            // elsewhere on the mailbox row itself.
            return false;
        }

        return $this->emailsMatch($username, $ruaEmail);
    }

    /**
     * Same matching rule as {@see matchesConnectedMailbox()} but against an
     * already-known mailbox instance — used by {@see \App\Services\IngestionPathResolver}
     * where the matrix query already returned a specific `mailboxId` per row,
     * so the lookup-by-domain step is redundant. Keeps the two call sites
     * sharing the same email-comparison logic.
     */
    public function matchesMailbox(MailboxConnection $mailbox, ?string $ruaEmail): bool
    {
        if (null === $ruaEmail || '' === trim($ruaEmail)) {
            return false;
        }

        // TASK-135: paused (isActive=false) AND soft-deleted (TASK-133
        // disconnectedAt != null) mailboxes don't "route reports anywhere we
        // can ingest" — the cron skips them. Mirrors the guard the
        // findMailboxForDomain branch already runs (line 120) so the
        // IngestionPathResolver path doesn't leave the "Ingesting via mailbox"
        // success badge flipped on after disconnect.
        if (!$mailbox->isActive || null !== $mailbox->disconnectedAt) {
            return false;
        }

        try {
            $username = $this->credentialEncryptor->decrypt($mailbox->encryptedUsername);
        } catch (\RuntimeException) {
            return false;
        }

        return $this->emailsMatch($username, $ruaEmail);
    }

    private function findMailboxForDomain(string $domainId): ?MailboxConnection
    {
        if ('' === trim($domainId)) {
            return null;
        }

        try {
            $domainUuid = Uuid::fromString($domainId);
        } catch (\InvalidArgumentException) {
            // Malformed UUID — defensive guard so a caller passing a stringly
            // typed `'domain-id'` literal (legacy fixtures, snapshot tests)
            // doesn't blow up here.
            return null;
        }

        // First active mailbox bound to this domain wins. v1 doesn't model the
        // multi-mailbox-per-domain case explicitly — operators with a single
        // connected mailbox per domain are the dominant shape we're optimising
        // for here. Inactive mailboxes don't count: a paused mailbox isn't
        // "routing reports anywhere we can ingest", regardless of credentials.
        $candidates = $this->mailboxConnectionRepository->findByDomain($domainUuid);
        foreach ($candidates as $candidate) {
            if ($candidate->isActive) {
                return $candidate;
            }
        }

        return null;
    }

    private function emailsMatch(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        // Strip `mailto:` defensively even though DmarcRecordParser already
        // removes it — costs nothing to be robust against future regressions.
        if (str_starts_with($a, 'mailto:')) {
            $a = substr($a, 7);
        }
        if (str_starts_with($b, 'mailto:')) {
            $b = substr($b, 7);
        }

        // Both sides must look like an email — bail if either is missing an `@`
        // so a non-email IMAP login (e.g. `u12345`) doesn't accidentally
        // false-match.
        if (false === strpos($a, '@') || false === strpos($b, '@')) {
            return false;
        }

        return $a === $b;
    }
}
