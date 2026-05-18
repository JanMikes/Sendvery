<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\Dns\SpfChecker;
use App\Value\Dns\SpfCheckResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'SpfChecker', template: 'components/SpfChecker.html.twig')]
final class SpfCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    public ?SpfCheckResult $result = null;

    public function __construct(
        private readonly SpfChecker $checker,
    ) {
    }

    public function mount(string $domain = ''): void
    {
        $this->domain = $domain;

        if ('' !== trim($this->domain)) {
            $this->check();
        }
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
