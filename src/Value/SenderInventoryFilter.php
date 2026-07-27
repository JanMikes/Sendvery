<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The `?filter=` axis on `/app/domains/{id}/senders`.
 *
 * {@see Unauthorized} is the legacy value — it predates the
 * {@see SenderReviewState} split and means "everything that is not
 * authorized", i.e. both {@see NeedsReview} and {@see NotAuthorized}. It stays
 * supported because bookmarks and older links point at it; the visible tabs
 * use the two precise values instead.
 */
enum SenderInventoryFilter: string
{
    case Authorized = 'authorized';
    case Unauthorized = 'unauthorized';
    case NeedsReview = 'needs_review';
    case NotAuthorized = 'not_authorized';
}
