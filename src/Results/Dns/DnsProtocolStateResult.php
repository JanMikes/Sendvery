<?php

declare(strict_types=1);

namespace App\Results\Dns;

use App\Value\DnsCheckType;
use App\Value\ProtocolState;

/**
 * The newest stored `dns_check_result` row for one (domain, protocol) pair.
 *
 * This is the AUTHORITATIVE source for "is this record published and healthy?".
 * Every DNS check path — the nightly `sendvery:dns:check-all` sweep, the
 * queued first check on domain add, the manual re-verify button, the onboarding
 * verify frame — writes one row per protocol through
 * {@see \App\Services\Dns\DnsMonitor}, so a row exists the moment a check has
 * run.
 *
 * WHY this exists: the setup checklist used to read per-protocol state from the
 * newest `domain_health_snapshot` instead. Snapshots were only written by the
 * 03:00 cron, and MX had no `*_verified_at` fallback column at all — so a
 * freshly added domain with perfectly valid MX records reported "MX records not
 * detected" until the next nightly sweep. Reading the check rows removes the
 * whole class of "correct DNS, wrong verdict" bug for all four protocols.
 */
final readonly class DnsProtocolStateResult
{
    public function __construct(
        public DnsCheckType $type,
        public \DateTimeImmutable $checkedAt,
        public ?string $rawRecord,
        public bool $isValid,
    ) {
    }

    /**
     * @param array{check_type: string, checked_at: string, raw_record: string|null, is_valid: bool|int|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            type: DnsCheckType::from($row['check_type']),
            checkedAt: new \DateTimeImmutable($row['checked_at']),
            rawRecord: $row['raw_record'],
            isValid: self::toBool($row['is_valid']),
        );
    }

    /**
     * `raw_record IS NULL` is how {@see \App\Services\Dns\DnsMonitor} records
     * "we looked and there was nothing there" — so a null raw record is
     * Missing (publish one), while a stored record that failed validation is
     * Invalid (fix the one you have). The distinction drives whether we tell
     * the user to ADD or to EDIT a record.
     */
    public function protocolState(): ProtocolState
    {
        if ($this->isValid) {
            return ProtocolState::Configured;
        }

        return null === $this->rawRecord || '' === trim($this->rawRecord)
            ? ProtocolState::Missing
            : ProtocolState::Invalid;
    }

    private static function toBool(bool|int|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 't', 'true', 'TRUE'], true);
    }
}
