<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Stores a DMARC record's RFC 7489 policy-override reasons as JSON while
 * keeping the entity's API a list of {@see PolicyOverrideReason} objects.
 *
 * The conversion lives here, in one tested place, rather than in the entity or
 * at each call site — which is what keeps "objects over arrays" true all the
 * way up: no caller ever sees `['type' => …, 'comment' => …]`.
 *
 * Decoding is deliberately forgiving. The underlying column is plain JSON, so a
 * future migration, a manual fix-up or an older writer could leave a shape this
 * class does not recognise; a row that cannot be read as reasons degrades to
 * "no reasons recorded" rather than making an entire DMARC record unhydratable.
 * That mirrors the parser's own posture toward third-party input.
 */
final class PolicyOverrideReasonsType extends JsonType
{
    public const string NAME = 'policy_override_reasons';

    /**
     * @return list<PolicyOverrideReason>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        $decoded = parent::convertToPHPValue($value, $platform);

        if (!is_array($decoded)) {
            return [];
        }

        $reasons = [];

        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['type']) || !is_string($row['type'])) {
                continue;
            }

            $comment = $row['comment'] ?? null;

            $reasons[] = new PolicyOverrideReason(
                type: PolicyOverrideReasonType::fromReportValue($row['type']),
                comment: is_string($comment) ? $comment : null,
            );
        }

        return $reasons;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $reason) {
            // Anything that is not a PolicyOverrideReason reached the property
            // around its declared type. Dropping it keeps the column readable
            // instead of persisting a shape convertToPHPValue would discard.
            if ($reason instanceof PolicyOverrideReason) {
                $rows[] = [
                    'type' => $reason->type->value,
                    'comment' => $reason->comment,
                ];
            }
        }

        return parent::convertToDatabaseValue($rows, $platform);
    }
}
