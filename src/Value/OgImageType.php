<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Discriminator for the three flavours of dynamically generated Open
 * Graph share cards: per-tool pages, per-knowledge-base article, and
 * per-public-domain-health share. The string values are part of the
 * public `/og/{type}/{slug}` URL — changing them invalidates already
 * shared social-card URLs in the wild.
 */
enum OgImageType: string
{
    case Tool = 'tool';
    case Kb = 'kb';
    case Health = 'health';
}
