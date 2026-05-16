<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\Dns\DmarcChecker;
use App\Value\Dns\DmarcCheckResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'DmarcChecker', template: 'components/DmarcChecker.html.twig')]
final class DmarcCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    public ?DmarcCheckResult $result = null;

    public function __construct(
        private readonly DmarcChecker $checker,
    ) {
    }

    #[LiveAction]
    public function check(): void
    {
        $domain = trim($this->domain);

        if ('' === $domain) {
            return;
        }

        $this->result = $this->checker->check($domain);
    }
}
