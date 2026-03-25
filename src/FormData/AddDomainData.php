<?php

declare(strict_types=1);

namespace App\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class AddDomainData
{
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
        message: 'Please enter a valid domain name (e.g. example.com).',
    )]
    public string $domainName = '';
}
