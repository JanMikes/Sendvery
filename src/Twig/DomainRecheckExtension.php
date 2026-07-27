<?php

declare(strict_types=1);

namespace App\Twig;

use App\Services\Dns\DomainRecheckThrottle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the manual DNS re-check throttle state to Twig so the shared
 * RecheckDnsButton component can render itself disabled with a countdown
 * instead of offering a click that will only be bounced.
 *
 * A peek — never a consume — so painting the button costs the user nothing.
 */
final class DomainRecheckExtension extends AbstractExtension
{
    public function __construct(
        private readonly DomainRecheckThrottle $domainRecheckThrottle,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('domain_recheck_availability', $this->domainRecheckThrottle->peek(...)),
        ];
    }
}
