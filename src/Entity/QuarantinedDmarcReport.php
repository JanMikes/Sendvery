<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Reports\QuarantineReason;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Holds a parsed DMARC report we couldn't route to a team — either because
 * no one has the domain monitored, or because the domain exists but isn't
 * verified yet. Released back into the normal report pipeline when a team
 * verifies the matching domain (see ReleaseQuarantinedReportsWhenDomainVerified).
 *
 * `expires_at` caps how long we hold these so the table doesn't grow forever.
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

    #[ORM\Column(type: 'string', length: 32, enumType: QuarantineReason::class)]
    public readonly QuarantineReason $reason;

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
