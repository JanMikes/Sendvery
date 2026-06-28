<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;

/**
 * The single place that serializes a DMARC TXT record from tags. Two entry
 * points:
 *
 *  - {@see rebuildRecord()} — the array-tag path extracted verbatim from
 *    DmarcRuaInstruction. It preserves canonical ordering for the known tags
 *    and appends any unknown customer tags (ri/rf/custom) untouched, so the
 *    self-TXT "append our rua" merge stays byte-identical.
 *  - {@see serialize()} — the typed path for the fully-controlled managed
 *    (hosted) policy, where Sendvery owns every tag.
 *
 * Cosmetic ordering note: the self-TXT *default* string emitted by
 * DmarcRuaInstruction is `v; p; rua; fo; adkim; aspf` (a hand-written literal),
 * whereas the canonical array order used here is
 * `v; p; sp; rua; ruf; adkim; aspf; pct; fo; rf; ri`. The difference is purely
 * cosmetic — both are valid DMARC — and only the merge/managed paths flow
 * through this canonical order.
 */
final readonly class DmarcRecordSerializer
{
    /** @var list<string> */
    private const array CANONICAL_ORDER = ['v', 'p', 'sp', 'rua', 'ruf', 'adkim', 'aspf', 'pct', 'fo', 'rf', 'ri'];

    /**
     * Serialize a fully-controlled DMARC policy (the managed/hosted record).
     * `pct` is omitted when 100, `sp` when null; `rua` is the comma-joined
     * `mailto:`+address list with no spaces; `ruf` is never emitted.
     *
     * @param list<string> $ruaAddresses
     */
    public function serialize(
        DmarcPolicy $p,
        ?DmarcPolicy $sp,
        int $pct,
        array $ruaAddresses,
        ?DmarcAlignment $adkim = null,
        ?DmarcAlignment $aspf = null,
        ?string $fo = null,
    ): string {
        $tags = [
            'v' => 'DMARC1',
            'p' => $p->value,
        ];

        if (null !== $sp) {
            $tags['sp'] = $sp->value;
        }

        if ([] !== $ruaAddresses) {
            $tags['rua'] = implode(',', array_map(static fn (string $addr): string => 'mailto:'.$addr, $ruaAddresses));
        }

        if (null !== $adkim) {
            $tags['adkim'] = $adkim->value;
        }

        if (null !== $aspf) {
            $tags['aspf'] = $aspf->value;
        }

        if (100 !== $pct) {
            $tags['pct'] = (string) $pct;
        }

        if (null !== $fo) {
            $tags['fo'] = $fo;
        }

        return $this->rebuildRecord($tags);
    }

    /**
     * Rebuild a record from arbitrary tags, ordering the known DMARC tags
     * canonically and appending any unknown tags in their original order.
     *
     * @param array<string, string> $tags
     */
    public function rebuildRecord(array $tags): string
    {
        $ordered = [];
        foreach (self::CANONICAL_ORDER as $key) {
            if (array_key_exists($key, $tags)) {
                $ordered[$key] = $tags[$key];
                unset($tags[$key]);
            }
        }

        $ordered = [...$ordered, ...$tags];

        $parts = [];
        foreach ($ordered as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $value);
        }

        return implode('; ', $parts);
    }
}
