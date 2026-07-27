<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One receiver-supplied explanation for overriding the published DMARC policy
 * (RFC 7489 §6.7). A single `<record>` may carry several of these, so this is
 * always handled as a list, never as a single value.
 */
final readonly class PolicyOverrideReason
{
    /**
     * The `<comment>` is free text written by whoever sent the report, and
     * RFC 7489 puts no bound on it — a broken or hostile reporter could ship
     * kilobytes per record, multiplied by every record in every report. 255
     * matches the width of the record's other text columns and is orders of
     * magnitude more than the real-world payloads ("arc=pass", "sampled out"),
     * so it costs nothing legitimate and removes the amplification vector.
     */
    public const int MAX_COMMENT_LENGTH = 255;

    public PolicyOverrideReasonType $type;

    /**
     * Untrusted third-party text, stored verbatim (within the bound) so the
     * evidence stays faithful. Sanitisation is an egress concern, not a storage
     * one: anything that renders this to a user or feeds it to a model MUST put
     * it through {@see \App\Services\Ai\Security\UntrustedDataSanitizer} first,
     * exactly as org names and hostnames from the same reports already are.
     * Nothing reads this field yet, so nothing does either today.
     */
    public ?string $comment;

    public function __construct(PolicyOverrideReasonType $type, ?string $comment = null)
    {
        $this->type = $type;
        $this->comment = self::boundComment($comment);
    }

    /**
     * Truncation is a hard cut with no ellipsis: this value is evidence, not
     * copy, and a marker glued onto it would be indistinguishable from text the
     * reporter actually sent.
     */
    private static function boundComment(?string $comment): ?string
    {
        if (null === $comment) {
            return null;
        }

        $trimmed = trim($comment);

        if ('' === $trimmed) {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_COMMENT_LENGTH);
    }
}
