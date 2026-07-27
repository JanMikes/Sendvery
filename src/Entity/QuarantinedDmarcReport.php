<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Reports\QuarantineReason;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Holds a DMARC report we couldn't hand to a team — because no one has the
 * domain monitored, because the domain exists but isn't verified yet, or
 * because the owning team had no monthly report headroom left. Released back
 * into the normal report pipeline when the blocker clears: domain verification
 * (ReleaseQuarantinedReportsWhenDomainVerified) or returning plan capacity —
 * an upgrade or the monthly period rolling (ReleaseQuarantinedReportsForTeamHandler).
 *
 * `expires_at` caps how long we hold the domain-problem reasons so the table
 * doesn't grow forever on mail we can never hand to anyone. It does NOT apply
 * to `plan_overage`: see {@see QuarantineReason::isTtlPurgeable()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quarantined_dmarc_report')]
#[ORM\Index(name: 'idx_quarantine_domain', columns: ['domain_name', 'quarantined_at'])]
#[ORM\Index(name: 'idx_quarantine_expires', columns: ['expires_at'])]
final class QuarantinedDmarcReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: ReceivedReportEmail::class)]
    #[ORM\JoinColumn(name: 'received_email_id', nullable: false, onDelete: 'CASCADE')]
    public readonly ReceivedReportEmail $receivedEmail;

    #[ORM\Column(type: 'text')]
    public readonly string $domainName;

    #[ORM\Column(type: 'text')]
    public readonly string $externalReportId;

    #[ORM\Column(type: 'text')]
    public readonly string $reporterOrg;

    #[ORM\Column(type: 'text')]
    public readonly string $reporterEmail;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $dateRangeBegin;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $dateRangeEnd;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $quarantinedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $expiresAt;

    /**
     * Mutable (unlike its siblings) for exactly one transition: see
     * {@see markBlockedByPlanCap()}.
     */
    #[ORM\Column(type: 'string', length: 32, enumType: QuarantineReason::class)]
    public QuarantineReason $reason;

    #[ORM\Column(type: 'blob')]
    public readonly mixed $reportXmlGz;

    public function __construct(
        UuidInterface $id,
        ReceivedReportEmail $receivedEmail,
        string $domainName,
        string $externalReportId,
        string $reporterOrg,
        string $reporterEmail,
        \DateTimeImmutable $dateRangeBegin,
        \DateTimeImmutable $dateRangeEnd,
        \DateTimeImmutable $quarantinedAt,
        \DateTimeImmutable $expiresAt,
        QuarantineReason $reason,
        string $reportXmlGz,
    ) {
        $this->id = $id;
        $this->receivedEmail = $receivedEmail;
        $this->domainName = strtolower($domainName);
        $this->externalReportId = $externalReportId;
        $this->reporterOrg = $reporterOrg;
        $this->reporterEmail = $reporterEmail;
        $this->dateRangeBegin = $dateRangeBegin;
        $this->dateRangeEnd = $dateRangeEnd;
        $this->quarantinedAt = $quarantinedAt;
        $this->expiresAt = $expiresAt;
        $this->reason = $reason;
        $this->reportXmlGz = $reportXmlGz;
    }

    /**
     * The domain is sorted out (monitored and verified) and the team's monthly
     * report cap is now the only thing keeping this report parked.
     *
     * Re-stamping matters for two reasons a user can see: `plan_overage` rows
     * are excluded from the TTL purge, so a report we are withholding for a
     * billing reason can never be deleted for a verification reason; and the
     * billing page's "N reports waiting … upgrade to unlock" count reads this
     * reason, so the number matches what is actually being withheld.
     *
     * One-way — a row never goes back to describing a domain problem it no
     * longer has.
     */
    public function markBlockedByPlanCap(): void
    {
        $this->reason = QuarantineReason::PlanOverage;
    }

    public function reportXmlBytes(): string
    {
        if (is_resource($this->reportXmlGz)) {
            $contents = stream_get_contents($this->reportXmlGz);
            assert(false !== $contents);
            rewind($this->reportXmlGz);

            return $contents;
        }

        assert(is_string($this->reportXmlGz));

        return $this->reportXmlGz;
    }

    public function decompressedXml(): string
    {
        $decompressed = gzdecode($this->reportXmlBytes());
        if (false === $decompressed) {
            throw new \RuntimeException('Failed to decompress quarantined DMARC XML.');
        }

        return $decompressed;
    }
}
