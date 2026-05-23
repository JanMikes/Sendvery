<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by an OgImage content resolver when the requested slug doesn't
 * correspond to any known piece of content (e.g. unknown tool slug, KB
 * article slug, or domain-health share hash). The OgImageController
 * catches this and falls back to the default static OG image.
 */
final class OgImageContentNotFoundException extends \RuntimeException
{
}
