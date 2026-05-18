<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DnsCheckCompleted;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AlertOnDnsChange
{
    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private TeamRepository $teamRepository,
    ) {
    }

    public function __invoke(DnsCheckCompleted $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);
        $team = $this->teamRepository->get($event->teamId);
        $typeName = strtoupper($event->type->value);

        // First check ever for this domain+type and it's already broken — alert immediately.
        // Without this, a domain added with a pre-existing misconfiguration (e.g. a CNAME
        // pointing at a selector the provider hasn't published yet) would never trigger a
        // change-based alert, since there's no prior state to compare against.
        if ($event->isFirstCheck && !$event->isValid) {
            $this->alertEngine->createAlert(
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::DnsRecordInvalid,
                severity: AlertSeverity::Critical,
                title: "{$typeName} is broken for {$domain->domain}",
                message: "We detected an issue with the {$typeName} record for {$domain->domain} on the first monitoring check. Review the details and fix the configuration to restore email authentication.",
                data: [
                    'dns_check_type' => $event->type->value,
                    'current_record' => $event->rawRecord,
                    'first_check' => true,
                ],
            );

            return;
        }

        if (null !== $event->previousRawRecord && null === $event->rawRecord) {
            $this->alertEngine->createAlert(
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::DnsRecordMissing,
                severity: AlertSeverity::Critical,
                title: "{$typeName} record removed for {$domain->domain}",
                message: "The {$typeName} record for {$domain->domain} was previously present but is now missing. This can impact email delivery and authentication.",
                data: [
                    'dns_check_type' => $event->type->value,
                    'previous_record' => $event->previousRawRecord,
                ],
            );

            return;
        }

        if ($event->hasChanged && null !== $event->rawRecord) {
            if (!$event->isValid) {
                $this->alertEngine->createAlert(
                    team: $team,
                    monitoredDomain: $domain,
                    type: AlertType::DnsRecordInvalid,
                    severity: AlertSeverity::Critical,
                    title: "{$typeName} record is now invalid for {$domain->domain}",
                    message: "The {$typeName} record for {$domain->domain} has changed and is now invalid. Check your DNS configuration immediately.",
                    data: [
                        'dns_check_type' => $event->type->value,
                        'current_record' => $event->rawRecord,
                        'previous_record' => $event->previousRawRecord,
                    ],
                );

                return;
            }

            $this->alertEngine->createAlert(
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::DnsRecordChanged,
                severity: AlertSeverity::Warning,
                title: "{$typeName} record changed for {$domain->domain}",
                message: "The {$typeName} record for {$domain->domain} has been modified. Review the change to ensure it was intentional.",
                data: [
                    'dns_check_type' => $event->type->value,
                    'current_record' => $event->rawRecord,
                    'previous_record' => $event->previousRawRecord,
                ],
            );
        }
    }
}
