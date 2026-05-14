<?php

declare(strict_types=1);

namespace App\FormData;

use App\Value\SubscriptionPlan;
use Symfony\Component\Validator\Constraints as Assert;

final class BetaAccessRequestData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Length(max: 255)]
    public ?string $company = null;

    public SubscriptionPlan $requestedPlan = SubscriptionPlan::Personal;

    #[Assert\Range(min: 1, max: 10000)]
    public ?int $domainCount = null;

    #[Assert\Length(max: 2000)]
    public ?string $message = null;
}
