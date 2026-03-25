<?php

declare(strict_types=1);

namespace App\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class BetaSignupData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    public ?int $domainCount = null;

    #[Assert\Length(max: 500)]
    public ?string $painPoint = null;
}
