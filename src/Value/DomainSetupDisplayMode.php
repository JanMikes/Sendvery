<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Controls which of the two TASK-067 / TASK-080 cards render on the domain
 * detail page in a given state (TASK-097). Computed on
 * {@see \App\Services\DomainSetupStatusResolver} so the Twig components stay
 * props-only renderers — they branch on `status.displayMode.value`.
 *
 * - `BannerOnly` (all-green): the one-line "Monitoring active" banner is
 *   enough; the redundant DNS-setup-complete confirmation card hides.
 * - `PanelOnly` (no DNS check yet): the info-blue "We haven't checked DNS
 *   yet" panel leads; the banner hides — there's no verdict to declare yet,
 *   and the old banner copy ("DNS not configured yet") was a wrong-information
 *   bug a first-time user hit in their first 5 minutes.
 * - `BannerAndPanel` (partial setup): TL;DR banner + per-protocol checklist
 *   render together; the banner shrinks its bottom margin so the two cards
 *   read as a unit instead of two stacked cards.
 */
enum DomainSetupDisplayMode: string
{
    case BannerOnly = 'banner_only';
    case PanelOnly = 'panel_only';
    case BannerAndPanel = 'banner_and_panel';
}
