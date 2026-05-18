<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Services\Dns\DomainHealthScorer;
use App\Services\Dns\EmailAuthChecker;
use App\Value\Dns\DomainHealthScore;
use App\Value\Dns\EmailAuthCheckResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(name: 'EmailAuthChecker', template: 'components/EmailAuthChecker.html.twig')]
final class EmailAuthCheckerComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $domain = '';

    public ?EmailAuthCheckResult $result = null;
    public ?DomainHealthScore $healthScore = null;

    public function __construct(
        private readonly EmailAuthChecker $checker,
        private readonly DomainHealthScorer $scorer,
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
        $this->healthScore = $this->scorer->score($this->result);
    }
}
