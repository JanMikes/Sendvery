<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\Dns\PolicyChangeSource;

/**
 * One row of the managed-DMARC "Recent changes" audit panel.
 */
final readonly class ManagedDmarcPolicyChangeResult
{
    public function __construct(
        public string $id,
        public PolicyChangeSource $source,
        public ?string $fromPolicy,
        public string $toPolicy,
        public ?string $actorUserId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array{id: string, source: string, from_policy: ?string, to_policy: string, actor_user_id: ?string, created_at: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            source: PolicyChangeSource::from($row['source']),
            fromPolicy: $row['from_policy'],
            toPolicy: $row['to_policy'],
            actorUserId: $row['actor_user_id'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
