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
            return $this->resolveOrThrow($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Like {@see resolve()} but lets a lookup FAILURE propagate: a returned null
     * means "looked up successfully, no CNAME", whereas an exception means "could
     * not look up at all". Callers that must not conflate the two (managed-DMARC
     * verification + teardown) use this so a transient blip never reads as
     * "the CNAME is gone".
     */
    public function resolveOrThrow(string $name): ?string
    {
        $records = $this->dns->getRecords($name, 'CNAME');

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
