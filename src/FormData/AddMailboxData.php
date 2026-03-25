<?php

declare(strict_types=1);

namespace App\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class AddMailboxData
{
    #[Assert\NotBlank]
    public string $host = '';

    #[Assert\NotBlank]
    #[Assert\Positive]
    public int $port = 993;

    #[Assert\NotBlank]
    public string $username = '';

    #[Assert\NotBlank]
    public string $password = '';

    #[Assert\NotBlank]
    public string $encryption = 'ssl';

    #[Assert\NotBlank]
    public string $type = 'imap_user';
}
