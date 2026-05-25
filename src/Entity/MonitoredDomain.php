<?php

declare(strict_types=1);

namespace App\Entity;

use App\Events\DomainAdded;
use App\Events\DomainDmarcVerified;
use App\Value\DmarcPolicy;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * Domain ownership is system-wide unique: at most one team can monitor a
 * given domain at any time, enforced by a case-insensitive functional unique
 * index in the database (see migration Version20260523100000). The Add-time
 * check in AddDomainController catches the conflict early and redirects the
 * user to the "domain taken" page; the index is the race-condition backstop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'monitored_domain')]
final class MonitoredDomain implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    public readonly Team $team;

    #[ORM\Column(length: 255)]
    public string $domain;

    #[ORM\Column(type: 'string', nullable: true, enumType: DmarcPolicy::class)]
    public ?DmarcPolicy $dmarcPolicy;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $spfVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $dkimVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $dmarcVerifiedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $firstReportAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    /**
     * TASK-146 — per-domain DKIM selector preference. When NULL, the DkimChecker
     * brute-forces selectors from DkimSelectorRegistry::PROVIDER_SELECTORS;
     * when set, the checker queries this selector directly. Teams whose
     * selector isn't in the canonical registry (custom selectors from
     * internal rotation, niche providers, etc.) set this so the dashboard
     * stops reporting "DKIM not found" forever.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $dkimSelector;

    public function __construct(
        UuidInterface $id,
        Team $team,
        string $domain,
        \DateTimeImmutable $createdAt,
        ?DmarcPolicy $dmarcPolicy = null,
        ?\DateTimeImmutable $spfVerifiedAt = null,
        ?\DateTimeImmutable $dkimVerifiedAt = null,
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        ?string $dkimSelector = null,
    ) {
        $this->id = $id;
        $this->team = $team;
        $this->domain = $domain;
        $this->createdAt = $createdAt;
        $this->dmarcPolicy = $dmarcPolicy;
        $this->spfVerifiedAt = $spfVerifiedAt;
        $this->dkimVerifiedAt = $dkimVerifiedAt;
        $this->dmarcVerifiedAt = $dmarcVerifiedAt;
        $this->firstReportAt = $firstReportAt;
        $this->dkimSelector = $dkimSelector;

        $this->recordThat(new DomainAdded($this->id, $this->team->id));
    }

    /**
     * Records the first successful DMARC DNS verification and emits a
     * DomainDmarcVerified event so listeners (notably the quarantine
     * releaser) can react. Re-verifications are a no-op event-wise so we
     * don't fire duplicate releases on every nightly DNS sweep.
     */
    public function markDmarcVerified(\DateTimeImmutable $verifiedAt): void
    {
        $wasUnverified = null === $this->dmarcVerifiedAt;
        $this->dmarcVerifiedAt = $verifiedAt;

        if ($wasUnverified) {
            $this->recordThat(new DomainDmarcVerified(
                domainId: $this->id,
                teamId: $this->team->id,
                domainName: $this->domain,
            ));
        }
    }
}
