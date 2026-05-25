<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
#[ORM\Table(name: 'contact_inquiry')]
#[ORM\Index(name: 'idx_contact_inquiry_submitted_at', columns: ['submitted_at'])]
final class ContactInquiry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(length: 255)]
    public readonly string $name;

    #[ORM\Column(length: 255)]
    public readonly string $email;

    #[ORM\Column(length: 255)]
    public readonly string $subject;

    #[ORM\Column(type: 'text')]
    public readonly string $message;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $submittedAt;

    #[ORM\Column(length: 45, nullable: true)]
    public readonly ?string $submitterIp;

    #[ORM\Column(length: 512, nullable: true)]
    public readonly ?string $userAgent;

    public function __construct(
        UuidInterface $id,
        string $name,
        string $email,
        string $subject,
        string $message,
        \DateTimeImmutable $submittedAt,
        ?string $submitterIp,
        ?string $userAgent,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
        $this->submittedAt = $submittedAt;
        $this->submitterIp = $submitterIp;
        $this->userAgent = $userAgent;
    }
}
