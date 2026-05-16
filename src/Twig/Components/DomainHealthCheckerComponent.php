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

#[AsLiveComponent(name: 'DomainHealthChecker', template: 'components/DomainHealthChecker.html.twig')]
final class DomainHealthCheckerComponent
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
