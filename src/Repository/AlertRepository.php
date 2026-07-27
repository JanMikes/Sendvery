<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alert;
use App\Exceptions\AlertNotFound;
use App\Value\AlertType;
use App\Value\DnsCheckType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class AlertRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * System-scoped lookup. Use ONLY from internal code paths where the alert
     * id originates from trusted state. User-facing controllers MUST go
     * through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): Alert
    {
        $alert = $this->entityManager->find(Alert::class, $id);

        if (null === $alert) {
            throw new AlertNotFound(sprintf('Alert with ID "%s" not found.', $id->toString()));
        }

        return $alert;
    }

    /**
     * Team-scoped lookup. Returns null when the alert is missing or owned by
     * a team the caller isn't a member of, so controllers translate to a 404
     * without leaking the existence of other tenants' alerts.
     *
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?Alert
    {
        if ([] === $teamIds) {
            return null;
        }

        $alert = $this->entityManager->find(Alert::class, $id);

        if (null === $alert) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($alert->team->id->equals($teamId)) {
                return $alert;
            }
        }

        return null;
    }

    /**
     * Every unread, unresolved alert of a team — regardless of snooze window.
     * "Mark all as read" flips the read flag on the whole backlog: snooze is an
     * independent axis (a snoozed alert stays hidden until its deadline whether
     * it is read or not), and resolved alerts are already out of the way.
     *
     * @return list<Alert>
     */
    public function findUnreadForTeam(UuidInterface $teamId): array
    {
        /** @var list<Alert> $alerts */
        $alerts = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.team = :teamId')
            ->andWhere('a.isRead = false')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('teamId', $teamId->toString())
            ->getQuery()
            ->getResult();

        return $alerts;
    }

    /**
     * Still-unresolved *problem* alerts raised for one domain and one DNS check
     * type — the set that a now-valid record retroactively fixes.
     *
     * Only DnsRecordInvalid/DnsRecordMissing qualify. DnsRecordChanged and
     * DnsRecordPublished are informational records of a transition, never a
     * problem, so "resolving" them would be meaningless — and excluding them
     * also sidesteps the ordering hazard of AlertOnDnsChange raising a fresh
     * informational alert for the very same recovering check.
     *
     * The check type lives inside the `data` JSON blob, which DQL cannot
     * reach — hence the raw `->>` predicate, followed by an ORM load so
     * callers still mutate managed entities.
     *
     * @return list<Alert>
     */
    public function findUnresolvedDnsProblemsForDomain(UuidInterface $domainId, DnsCheckType $checkType): array
    {
        $ids = $this->entityManager->getConnection()->executeQuery(
            "SELECT id FROM alert
             WHERE monitored_domain_id = :domainId
               AND type IN (:types)
               AND data->>'dns_check_type' = :checkType
               AND resolved_at IS NULL",
            [
                'domainId' => $domainId->toString(),
                'types' => [AlertType::DnsRecordInvalid->value, AlertType::DnsRecordMissing->value],
                'checkType' => $checkType->value,
            ],
            [
                'types' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn();

        if ([] === $ids) {
            return [];
        }

        /** @var list<Alert> $alerts */
        $alerts = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $alerts;
    }
}
