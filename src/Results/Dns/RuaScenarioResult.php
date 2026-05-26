<?php

declare(strict_types=1);

namespace App\Results\Dns;

use App\Value\Dns\RuaScenario;

/**
 * Resolver output: which RUA scenario a single domain is in today, plus —
 * when the scenario is `PointsAtExternal` — the external email address we
 * should name in the CTA copy. Null `ruaEmail` for the `NoRecord` and
 * `PointsAtSendvery` branches.
 */
final readonly class RuaScenarioResult
{
    public function __construct(
        public RuaScenario $scenario,
        public ?string $ruaEmail,
        public ?string $rawDmarcRecord = null,
        public int $ruaAddressCount = 0,
        public ?bool $reportAuthorizationFound = null,
    ) {
    }
}
