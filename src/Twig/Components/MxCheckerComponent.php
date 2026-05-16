<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\Dns\MxChecker;
use App\Value\Dns\MxCheckResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'MxChecker', template: 'components/MxChecker.html.twig')]
final class MxCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    public ?MxCheckResult $result = null;

    public function __construct(
        private readonly MxChecker $checker,
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
