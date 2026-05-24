<?php

declare(strict_types=1);

namespace App\Value;

/**
 * URL-driven sort axis for the Domain Health card on `/app`.
 *
 * Default behaviour (the controller picks `Worst` when no `?domain_health_sort=`
 * is supplied) surfaces problem domains first — which is the whole point of
 * the card. `Best` and `Most` are escape-hatch alternatives for users who want
 * to scan top performers or volume drivers respectively.
 */
enum DomainHealthSort: string
{
    case Worst = 'worst';
    case Best = 'best';
    case Most = 'most';
}
