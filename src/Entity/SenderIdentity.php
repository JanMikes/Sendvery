<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Dns\AsnRegistration;
use App\Value\SenderRole;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Global, IP-keyed cache of objective network facts about a sending host
 * (DEC-059 §3.1).
 *
 * Deliberately NOT merged with {@see KnownSender}: that entity is per-domain,
 * user-owned data (authorization, label, notes, volumes) and is never
 * auto-deleted. This one is a refreshable cache shared across every team — no
 * team_id, no user input, nothing here belongs to anybody. The "never delete
 * user data" rule therefore does not apply to it; it may be rebuilt at will.
 *
 * The persisted {@see $role} is the *signal-independent* baseline
 * (Forwarder / Esp / Unknown). OwnRelay and Suspicious are per-team judgements
 * that depend on `known_sender.is_authorized` and per-domain pass rates, so
 * writing them into a global row would leak one team's verdict onto another's
 * dashboard. Callers that hold those signals get the full five-way
 * classification from SenderIdentityResolver instead.
 *
 * {@see $forwardConfirmed} is an objective global fact for the same reason the
 * hostname is: either the claimed name resolves back to this address or it does
 * not, identically for every tenant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sender_identity')]
#[ORM\UniqueConstraint(name: 'uniq_sender_identity_source_ip', columns: ['source_ip'])]
#[ORM\Index(name: 'idx_sender_identity_registrable_domain', columns: ['registrable_domain'])]
#[ORM\Index(name: 'idx_sender_identity_asn', columns: ['asn'])]
final class SenderIdentity
{
    /**
     * Backoff before retrying an IP whose reverse lookup came back empty,
     * in hours, indexed by (attempts - 1) and clamped at the last entry.
     *
     * Plenty of real senders simply have no PTR record, so a failed lookup is a
     * permanent answer far more often than a transient one. Retrying every
     * ingest is what made reverse DNS a worker-stall risk in the first place
     * (DEC-059 D11); an hour, then six, then a day, then weekly keeps the door
     * open for the operator who publishes a PTR later without paying for it on
     * every report.
     *
     * @var list<int>
     */
    private const array RETRY_BACKOFF_HOURS = [1, 6, 24, 168];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 45)]
    public readonly string $sourceIp;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $hostname;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $registrableDomain;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $organization;

    #[ORM\Column(type: 'string', length: 20, enumType: SenderRole::class)]
    public SenderRole $role;

    /**
     * Whether {@see $hostname} survived forward-confirmed reverse DNS — the
     * claimed name resolving back to {@see $sourceIp}.
     *
     * Three-valued on purpose. `true` and `false` are answers; `null` means the
     * question was never asked, which is the state every row cached before
     * FCrDNS existed is in. Null is *not* trust: it grants nothing, and it makes
     * the row due for one re-resolution so a genuine forwarder earns its trust
     * back instead of inheriting it.
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    public ?bool $forwardConfirmed;

    /**
     * The autonomous system announcing {@see $sourceIp} (DEC-060 WP-D).
     *
     * As global and as objective as the hostname, and from a source the sender
     * does not write: BGP and the RIR that allocated the number, rather than a
     * reverse zone handed to whoever rents the address. Null means the address
     * is in no announced prefix, or the lookup did not answer — which is why
     * "have we ever asked?" needs its own column below rather than being
     * inferred from this one.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $asn;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $asnOrganization;

    /**
     * When the AS lookup was last performed, or null for a row cached before
     * this axis existed.
     *
     * The same three-valued discipline {@see $forwardConfirmed} uses, for the
     * same reason: `asn IS NULL` would otherwise mean both "this address is
     * announced by nobody" and "we have never looked", and those are not the
     * same fact. Null makes the row due for exactly one re-resolution.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $asnResolvedAt;

    /** When the cached facts were last written — successful lookup or not. */
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $resolvedAt;

    #[ORM\Column(type: 'integer')]
    public int $resolutionAttempts;

    /** When reverse DNS was last attempted; null for rows created without one. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastAttemptAt;

    public function __construct(
        UuidInterface $id,
        string $sourceIp,
        \DateTimeImmutable $resolvedAt,
        ?string $hostname = null,
        ?string $registrableDomain = null,
        ?string $organization = null,
        SenderRole $role = SenderRole::Unknown,
        int $resolutionAttempts = 0,
        ?\DateTimeImmutable $lastAttemptAt = null,
        ?bool $forwardConfirmed = null,
        ?int $asn = null,
        ?string $asnOrganization = null,
        ?\DateTimeImmutable $asnResolvedAt = null,
    ) {
        $this->id = $id;
        $this->sourceIp = $sourceIp;
        $this->resolvedAt = $resolvedAt;
        $this->hostname = $hostname;
        $this->registrableDomain = $registrableDomain;
        $this->organization = $organization;
        $this->role = $role;
        $this->resolutionAttempts = $resolutionAttempts;
        $this->lastAttemptAt = $lastAttemptAt;
        $this->forwardConfirmed = $forwardConfirmed;
        $this->asn = $asn;
        $this->asnOrganization = $asnOrganization;
        $this->asnResolvedAt = $asnResolvedAt;
    }

    /**
     * Records the outcome of an AS lookup, including the outcome "this address
     * is announced by nobody" — which is a real answer and is cached as one.
     */
    public function recordAsnLookup(?AsnRegistration $registration, \DateTimeImmutable $at): void
    {
        $this->asn = $registration?->number;
        $this->asnOrganization = $registration?->organization;
        $this->asnResolvedAt = $at;
    }

    public function asnRegistration(): ?AsnRegistration
    {
        return null === $this->asn ? null : new AsnRegistration($this->asn, $this->asnOrganization);
    }

    /**
     * Records the outcome of a reverse-DNS attempt. A null hostname is a
     * legitimate outcome and is stored as such — that negative result is the
     * whole point of the cache, because otherwise every ingest re-queries the
     * IPs that will never answer.
     */
    public function recordResolution(
        ?string $hostname,
        ?string $registrableDomain,
        ?string $organization,
        SenderRole $role,
        ?bool $forwardConfirmed,
        \DateTimeImmutable $at,
    ): void {
        $this->hostname = $hostname;
        $this->registrableDomain = $registrableDomain;
        $this->organization = $organization;
        $this->role = $role;
        $this->forwardConfirmed = $forwardConfirmed;
        $this->resolvedAt = $at;
        $this->lastAttemptAt = $at;
        ++$this->resolutionAttempts;
    }

    public function isResolved(): bool
    {
        return null !== $this->hostname;
    }

    /**
     * Whether the PTR hostname is safe to draw conclusions from. Only an
     * explicit `true` counts — an unanswered question is not a yes.
     */
    public function isForwardConfirmed(): bool
    {
        return true === $this->forwardConfirmed;
    }

    /**
     * A host that answered is never re-queried on the ingest path: PTR records
     * for mail infrastructure are effectively static, and the cost of a stale
     * hostname is far lower than the cost of stalling a worker.
     */
    public function isDueForRetry(\DateTimeImmutable $now): bool
    {
        if ($this->isResolved()) {
            // Except once, for rows cached before forward confirmation or the
            // AS lookup existed. Their hostname carries no verdict either way,
            // and an unverified PTR must never be trusted — so instead of
            // freezing them as permanently unconfirmed (which would demote
            // genuine forwarders forever) they get exactly one re-resolution to
            // earn an answer. A row that has never been asked for its AS is due
            // on the same terms, and for the same reason: never asked is not an
            // answer.
            return null === $this->forwardConfirmed || null === $this->asnResolvedAt;
        }

        if (null === $this->lastAttemptAt) {
            return true;
        }

        $index = max(0, min($this->resolutionAttempts, count(self::RETRY_BACKOFF_HOURS)) - 1);
        $retryAt = $this->lastAttemptAt->modify(sprintf('+%d hours', self::RETRY_BACKOFF_HOURS[$index]));

        return $now >= $retryAt;
    }
}
