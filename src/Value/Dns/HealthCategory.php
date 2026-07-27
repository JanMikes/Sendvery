<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class HealthCategory
{
    public function __construct(
        public string $name,
        /**
         * Null when this category has not been measured.
         *
         * Non-nullable, this field forced the scorer to invent a number for
         * anything it had not checked — and it invented 100, so a blacklist
         * lookup that never ran handed every domain a perfect fifth of its
         * grade. A category that has not been measured has no score, and that
         * is a different fact from scoring zero. The type now says so.
         */
        public ?int $score,
        public string $status,
    ) {
    }

    public function isMeasured(): bool
    {
        return null !== $this->score;
    }
}
