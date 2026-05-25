<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\MailboxConnection;
use App\Entity\Team;
use App\Repository\MailboxConnectionRepository;
use App\Services\CredentialEncryptor;
use App\Services\Dns\RuaMailboxMatcher;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * TASK-114: shared matcher that {@see \App\Services\IngestionPathResolver}
 * (per-row matrix decoration on `/app/mailboxes`) and
 * {@see \App\Services\DomainSetupStatusResolver} (5th RUA destination row on
 * `/app/domains/{id}`) consult to decide whether the published rua= address
 * routes to a connected mailbox the team is polling.
 *
 * The matching rule is intentionally tight (strip mailto:, lowercase, exact
 * local-part@domain equality) — alias forwarding, plus-tagging, etc. are out
 * of scope for v1. These tests pin every edge case of the rule so the two
 * surfaces stay in agreement on the canonical "is this a match?" question.
 */
final class RuaMailboxMatcherTest extends TestCase
{
    #[Test]
    public function matchesWhenDecryptedUsernameEqualsRuaEmail(): void
    {
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:dmarc@team.com');

        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::once())
            ->method('findByDomain')
            ->with($domainId)
            ->willReturn([$mailbox]);

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with('enc:dmarc@team.com')
            ->willReturn('dmarc@team.com');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertTrue($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function matchesIsCaseInsensitive(): void
    {
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:DMARC@Team.COM');

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willReturn('DMARC@Team.COM');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertTrue($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@TEAM.com'));
    }

    #[Test]
    public function matchesAfterStrippingMailtoPrefix(): void
    {
        // DmarcRecordParser already strips `mailto:` from rua addresses, but
        // the matcher does it again as a belt-and-braces guard so a future
        // upstream regression can't silently break the match.
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:reports@team.com');

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willReturn('reports@team.com');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertTrue($matcher->matchesConnectedMailbox($domainId->toString(), 'mailto:reports@team.com'));
    }

    #[Test]
    public function doesNotMatchWhenUsernamesDiffer(): void
    {
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:notifications@team.com');

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willReturn('notifications@team.com');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenRuaEmailIsNull(): void
    {
        // Defensive: the caller may pass a null rua email (no rua= tag in the
        // record) — the matcher must NOT consult the repo in that case.
        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::never())->method('findByDomain');

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox(Uuid::uuid7()->toString(), null));
    }

    #[Test]
    public function returnsFalseWhenRuaEmailIsEmptyString(): void
    {
        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::never())->method('findByDomain');

        $matcher = new RuaMailboxMatcher($repo, $this->createStub(CredentialEncryptor::class));

        self::assertFalse($matcher->matchesConnectedMailbox(Uuid::uuid7()->toString(), '   '));
    }

    #[Test]
    public function returnsFalseWhenNoConnectedMailboxForDomain(): void
    {
        $domainId = Uuid::uuid7();

        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::once())
            ->method('findByDomain')
            ->with($domainId)
            ->willReturn([]);

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenOnlyInactiveMailboxesAreBoundToDomain(): void
    {
        // Inactive mailbox isn't "routing reports anywhere we can ingest" —
        // even if credentials would otherwise match, the path-honest signal is
        // that this team isn't ingesting via that mailbox right now.
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:dmarc@team.com', isActive: false);

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenDecryptionFails(): void
    {
        // Encryption key rotated, ciphertext corrupted, etc. The matcher must
        // not flip the badge — fall through to the conservative warning.
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:bad');

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willThrowException(new \RuntimeException('decrypt failed'));

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenMailboxUsernameIsNotAnEmail(): void
    {
        // Some IMAP setups use a non-email username (e.g. "u12345"). We
        // can't safely "match" that against an email — bail.
        $domainId = Uuid::uuid7();
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:u12345');

        $repo = $this->createStub(MailboxConnectionRepository::class);
        $repo->method('findByDomain')->willReturn([$mailbox]);

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willReturn('u12345');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertFalse($matcher->matchesConnectedMailbox($domainId->toString(), 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenDomainIdIsMalformed(): void
    {
        // Legacy fixture / snapshot tests sometimes pass a string literal
        // like 'domain-id' instead of a real UUID — the matcher must bail
        // cleanly instead of throwing.
        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::never())->method('findByDomain');

        $matcher = new RuaMailboxMatcher($repo, $this->createStub(CredentialEncryptor::class));

        self::assertFalse($matcher->matchesConnectedMailbox('not-a-uuid', 'dmarc@team.com'));
    }

    #[Test]
    public function returnsFalseWhenDomainIdIsEmpty(): void
    {
        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::never())->method('findByDomain');

        $matcher = new RuaMailboxMatcher($repo, $this->createStub(CredentialEncryptor::class));

        self::assertFalse($matcher->matchesConnectedMailbox('   ', 'dmarc@team.com'));
    }

    #[Test]
    public function matchesMailboxVariantSkipsTheRepoLookup(): void
    {
        // The matrix path (IngestionPathResolver) already has a specific
        // mailbox instance from the query — going through matchesMailbox()
        // skips the redundant findByDomain round-trip.
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:dmarc@team.com');

        $repo = $this->createMock(MailboxConnectionRepository::class);
        $repo->expects(self::never())->method('findByDomain');

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with('enc:dmarc@team.com')
            ->willReturn('dmarc@team.com');

        $matcher = new RuaMailboxMatcher($repo, $encryptor);

        self::assertTrue($matcher->matchesMailbox($mailbox, 'dmarc@team.com'));
    }

    #[Test]
    public function matchesMailboxVariantReturnsFalseOnDecryptFailure(): void
    {
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:bad');

        $encryptor = $this->createStub(CredentialEncryptor::class);
        $encryptor->method('decrypt')->willThrowException(new \RuntimeException('decrypt failed'));

        $matcher = new RuaMailboxMatcher(
            $this->createStub(MailboxConnectionRepository::class),
            $encryptor,
        );

        self::assertFalse($matcher->matchesMailbox($mailbox, 'dmarc@team.com'));
    }

    #[Test]
    public function matchesMailboxVariantReturnsFalseOnNullRuaEmail(): void
    {
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:dmarc@team.com');

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher(
            $this->createStub(MailboxConnectionRepository::class),
            $encryptor,
        );

        self::assertFalse($matcher->matchesMailbox($mailbox, null));
    }

    #[Test]
    public function matchesMailboxVariantReturnsFalseForInactiveMailbox(): void
    {
        // TASK-135: paused mailboxes (isActive=false) don't route reports
        // anywhere we can ingest — sibling rule to findMailboxForDomain's
        // active-only loop. Without this guard the IngestionPathResolver
        // matrix path would still flip the "Ingesting via mailbox" badge on.
        $mailbox = $this->buildMailbox(encryptedUsername: 'enc:dmarc@team.com', isActive: false);

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher(
            $this->createStub(MailboxConnectionRepository::class),
            $encryptor,
        );

        self::assertFalse($matcher->matchesMailbox($mailbox, 'dmarc@team.com'));
    }

    #[Test]
    public function matchesMailboxVariantReturnsFalseForDisconnectedMailbox(): void
    {
        // TASK-135: soft-deleted mailboxes (TASK-133 disconnectedAt != null)
        // are skipped by the cron poller; matchesMailbox must mirror that so
        // the green "Ingesting via mailbox" badge doesn't linger on the
        // /app/mailboxes matrix row after the user clicked Disconnect.
        $mailbox = $this->buildMailbox(
            encryptedUsername: 'enc:dmarc@team.com',
            disconnectedAt: new \DateTimeImmutable('2026-05-25 10:00:00'),
        );

        $encryptor = $this->createMock(CredentialEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        $matcher = new RuaMailboxMatcher(
            $this->createStub(MailboxConnectionRepository::class),
            $encryptor,
        );

        self::assertFalse($matcher->matchesMailbox($mailbox, 'dmarc@team.com'));
    }

    private function buildMailbox(string $encryptedUsername, bool $isActive = true, ?\DateTimeImmutable $disconnectedAt = null): MailboxConnection
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'unit',
            slug: 'unit-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $team->popEvents();

        $mailbox = new MailboxConnection(
            id: $this->newId(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.team.com',
            port: 993,
            encryptedUsername: $encryptedUsername,
            encryptedPassword: 'enc:pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
            isActive: $isActive,
        );
        if (null !== $disconnectedAt) {
            $mailbox->disconnect($disconnectedAt);
        }
        $mailbox->popEvents();

        return $mailbox;
    }

    private function newId(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
