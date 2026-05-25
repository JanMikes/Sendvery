<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\InvalidDkimSelectorException;
use App\Message\CheckDomainDns;
use App\Message\SetDomainDkimSelector;
use App\Repository\MonitoredDomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SetDomainDkimSelectorHandler
{
    /**
     * RFC 1035 DNS label: 1-63 chars, alphanumeric + hyphens, not starting or
     * ending with a hyphen. A DKIM selector is a single label, not a full
     * domain — dots and underscores are rejected. Empty string clears the
     * preference (reverts to brute-force).
     */
    private const string DKIM_SELECTOR_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private EntityManagerInterface $entityManager,
        private CheckDomainDnsHandler $checkDomainDnsHandler,
    ) {
    }

    public function __invoke(SetDomainDkimSelector $message): void
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            $message->domainId,
            [Uuid::fromString($message->teamId)],
        );

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        $normalised = self::normalise($message->selector);

        if (null !== $normalised && 1 !== preg_match(self::DKIM_SELECTOR_PATTERN, $normalised)) {
            throw new InvalidDkimSelectorException('DKIM selector must be a valid DNS label: alphanumeric and hyphens only, not starting or ending with a hyphen, max 63 characters.');
        }

        if ($domain->dkimSelector === $normalised) {
            // Idempotent: no change, no re-verification dispatch. Avoids
            // double-fired DNS checks when the form is re-submitted with an
            // unchanged value.
            return;
        }

        $domain->dkimSelector = $normalised;
        $this->entityManager->flush();

        // Run the same handler the daily cron uses so the dns_check_result row
        // is written and the verification status reflects the new selector
        // immediately — visitors don't have to wait for the nightly sweep.
        ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $domain->id));
        $this->entityManager->flush();
    }

    private static function normalise(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }
}
