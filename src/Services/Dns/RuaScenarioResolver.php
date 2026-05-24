<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\MonitoredDomain;
use App\Repository\DnsCheckResultRepository;
use App\Results\Dns\RuaScenarioResult;
use App\Services\ReportAddressProvider;
use App\Value\Dns\RuaScenario;
use App\Value\DnsCheckType;
use Ramsey\Uuid\UuidInterface;

/**
 * Picks the {@see RuaScenario} for a single domain using only data already on
 * hand — the latest stored DMARC check (no live DNS lookup) and the configured
 * report address. Pure given those inputs, so it doesn't need a clock or any
 * write-side dependency.
 */
readonly class RuaScenarioResolver
{
    public function __construct(
        private DnsCheckResultRepository $dnsCheckResultRepository,
        private DmarcRecordParser $parser,
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    public function resolveForDomain(MonitoredDomain $domain): RuaScenarioResult
    {
        return $this->resolveForDomainId($domain->id);
    }

    public function resolveForDomainId(UuidInterface $domainId): RuaScenarioResult
    {
        $check = $this->dnsCheckResultRepository->findLatestForDomainAndType($domainId, DnsCheckType::Dmarc);
        if (null === $check || null === $check->rawRecord) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        $parsed = $this->parser->parse($check->rawRecord);
        if (null === $parsed) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        if ([] === $parsed->ruaAddresses) {
            return new RuaScenarioResult(RuaScenario::NoRecord, null);
        }

        foreach ($parsed->ruaAddresses as $address) {
            if ($this->isSendveryAddress($address)) {
                return new RuaScenarioResult(RuaScenario::PointsAtSendvery, $address);
            }
        }

        // No Sendvery address found — first rua= entry is the one we name in
        // the user-facing CTA copy ("Connect the inbox at <first>").
        return new RuaScenarioResult(RuaScenario::PointsAtExternal, $parsed->ruaAddresses[0]);
    }

    public function isSendveryAddress(string $email): bool
    {
        $lower = strtolower($email);

        if ($lower === strtolower($this->reportAddressProvider->get())) {
            return true;
        }

        $atPos = strrpos($lower, '@');
        if (false === $atPos) {
            return false;
        }

        $host = substr($lower, $atPos + 1);

        return 'sendvery.com' === $host;
    }
}
