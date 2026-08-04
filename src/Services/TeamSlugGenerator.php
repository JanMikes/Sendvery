<?php

declare(strict_types=1);

namespace App\Services;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Builds the slug for the default team a person gets on sign-up.
 *
 * `team.slug` is globally unique, so the suffix has to actually discriminate.
 * Both call sites used to take `substr($userId, 0, 8)`, which looks like an id
 * fragment and is not one: a UUID v7 opens with a 48-bit millisecond timestamp,
 * so its first 8 hex digits are the top 32 bits of that clock and only change
 * once every 2^16 ms — about every 65 seconds. Two people sharing an email
 * domain who signed up within the same minute got byte-identical slugs, and the
 * second one's first sign-in died on the unique index with a 500. On a consumer
 * domain that is not a rare race: any two gmail.com signups a minute apart.
 *
 * The trailing group is the random half of a v7, so it discriminates the way the
 * leading one only appeared to.
 */
final readonly class TeamSlugGenerator
{
    public function forUser(string $emailDomain, UuidInterface $userId): string
    {
        $prefix = (new AsciiSlugger())->slug($emailDomain)->lower()->toString();

        // The final group of a UUID is exactly 12 hex characters, and in v7 it
        // is random — 48 bits, so a collision needs ~17 million signups on one
        // email domain before it is even worth thinking about.
        $suffix = substr($userId->toString(), -12);

        return $prefix.'-'.$suffix;
    }
}
