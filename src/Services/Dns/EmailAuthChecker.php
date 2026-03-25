<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\EmailAuthCheckResult;

final readonly class EmailAuthChecker
{
    public function __construct(
        private SpfChecker $spfChecker,
        private DkimChecker $dkimChecker,
        private DmarcChecker $dmarcChecker,
        private MxChecker $mxChecker,
    ) {
    }

    public function check(string $domain): EmailAuthCheckResult
    {
        return new EmailAuthCheckResult(
            domain: $domain,
            spf: $this->spfChecker->check($domain),
            dkim: [$this->dkimChecker->check($domain)],
            dmarc: $this->dmarcChecker->check($domain),
            mx: $this->mxChecker->check($domain),
        );
    }
}
