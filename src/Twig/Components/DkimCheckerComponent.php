<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\Dns\DkimChecker;
use App\Value\Dns\DkimCheckResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'DkimChecker', template: 'components/DkimChecker.html.twig')]
final class DkimCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    #[LiveProp(writable: true)]
    public string $selector = '';

    public ?DkimCheckResult $result = null;

    public function __construct(
        private readonly DkimChecker $checker,
    ) {
    }

    #[LiveAction]
    public function check(): void
    {
        $domain = trim($this->domain);

        if ('' === $domain) {
            return;
        }

        $selector = trim($this->selector);

        $this->result = $this->checker->check($domain, '' !== $selector ? $selector : null);
    }
}
