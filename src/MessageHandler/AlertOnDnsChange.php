<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DnsCheckCompleted;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\Dns\DmarcSetupMode;
use App\Value\DnsCheckType;
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

        // Managed DMARC (DEC-058): Sendvery owns the hosted DMARC record, so a
        // change/invalid/missing on the DMARC check reflects our own policy
        // ramp or a CNAME issue — both are narrated by the managed card and the
        // dangling alert. Suppress the generic DNS-change alerts so we never nag
        // the customer about a record we manage.
        if (DnsCheckType::Dmarc === $event->type && DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode) {
            return;
        }

        $team = $this->teamRepository->get($event->teamId);
        $typeName = strtoupper($event->type->value);

        // First check ever for this domain+type and a record EXISTS but fails
        // validation — that's a genuinely broken pre-existing setup, alert
        // immediately (a change-based alert would never fire without prior
        // state to compare against). A missing record on the first check is
        // NOT an incident: freshly added domains are usually mid-setup, and
        // firing "X is broken" criticals for records the user never published
        // flooded real users with false alarms the moment the first check ran.
        // The setup checklist and health page own the "missing" guidance.
        if ($event->isFirstCheck && !$event->isValid && null !== $event->rawRecord) {
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

            // Nothing → valid is a first-time publication, not a suspicious
            // edit. Yellow "record changed, review it" made users read the
            // successful completion of their own setup as a fault, so this
            // transition gets its own green Success alert instead.
            if (null === $event->previousRawRecord || '' === trim($event->previousRawRecord)) {
                $this->alertEngine->createAlert(
                    team: $team,
                    monitoredDomain: $domain,
                    type: AlertType::DnsRecordPublished,
                    severity: AlertSeverity::Success,
                    title: "{$typeName} record published for {$domain->domain}",
                    message: "A valid {$typeName} record is now published for {$domain->domain}. This is the desired state — no action is needed, we're just letting you know it went live.",
                    data: [
                        'dns_check_type' => $event->type->value,
                        'current_record' => $event->rawRecord,
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
