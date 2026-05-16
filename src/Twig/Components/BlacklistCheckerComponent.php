<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\BlacklistChecker;
use App\Value\BlacklistResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'BlacklistChecker', template: 'components/BlacklistChecker.html.twig')]
final class BlacklistCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    public ?BlacklistResult $result = null;
    public bool $unresolved = false;

    public function __construct(
        private readonly BlacklistChecker $checker,
    ) {
    }

    #[LiveAction]
    public function check(): void
    {
        $domain = trim($this->domain);

        if ('' === $domain) {
            return;
        }

        $result = $this->checker->checkHostOrIp($domain);
        $this->unresolved = null === $result;
        $this->result = $result;
    }
}
