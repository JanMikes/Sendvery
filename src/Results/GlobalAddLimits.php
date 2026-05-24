<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Snapshot of the plan-limit state the top-bar "+ Add" dropdown needs to
 * render each of its three menu items. Computed once per request by
 * {@see \App\Twig\GlobalAddDropdownExtension} and exposed as a Twig global so
 * any dashboard page can render the dropdown without the controller passing
 * the data explicitly.
 *
 * `null()` returns an all-permissive zero-state used when the dashboard layout
 * renders on a page without an authenticated/onboarded user (e.g. the
 * onboarding flow itself, login, error pages). The dropdown won't actually
 * render there because the layout's `{% if app.user %}` gate is upstream, but
 * the safe default keeps the extension from blowing up.
 */
final readonly class GlobalAddLimits
{
    public function __construct(
        public bool $canAddDomain,
        public int $domainCount,
        public int $maxDomains,
        public bool $canAddMailbox,
        public bool $isTeamManager,
        public bool $canAddTeamMember,
        public int $effectiveMemberCount,
        public int $maxMembers,
    ) {
    }

    public static function null(): self
    {
        return new self(
            canAddDomain: false,
            domainCount: 0,
            maxDomains: 0,
            canAddMailbox: true,
            isTeamManager: false,
            canAddTeamMember: false,
            effectiveMemberCount: 0,
            maxMembers: 0,
        );
    }

    public function domainLimitDisplay(): string
    {
        return PHP_INT_MAX === $this->maxDomains ? '∞' : (string) $this->maxDomains;
    }

    public function memberLimitDisplay(): string
    {
        return PHP_INT_MAX === $this->maxMembers ? '∞' : (string) $this->maxMembers;
    }

    public function canInvite(): bool
    {
        return $this->isTeamManager && $this->canAddTeamMember;
    }
}
