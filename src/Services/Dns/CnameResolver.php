<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Spatie\Dns\Dns;
use Spatie\Dns\Records\CNAME;

/**
 * Resolves the CNAME target for a name, returning the first non-empty target
 * with the trailing dot stripped (or null on NXDOMAIN / no CNAME / lookup
 * failure). Extracted from DkimChecker so the DKIM check and the managed-DMARC
 * CNAME verification share one resolution path — and so DNS stays mockable via
 * the FakeDns alias.
 */
final readonly class CnameResolver
{
    public function __construct(
        private Dns $dns,
    ) {
    }

    public function resolve(string $name): ?string
    {
        try {
            $records = $this->dns->getRecords($name, 'CNAME');
        } catch (\Throwable) {
            return null;
        }

        foreach ($records as $record) {
            if ($record instanceof CNAME) {
                $target = rtrim($record->target(), '.');
                if ('' !== $target) {
                    return $target;
                }
            }
        }

        return null;
    }
}
