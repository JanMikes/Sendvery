<?php

declare(strict_types=1);

namespace App\Value;

final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = mb_strtolower(trim($value));

        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid email address: "%s"', $value));
        }

        $this->value = $normalized;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
