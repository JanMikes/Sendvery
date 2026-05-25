<?php

declare(strict_types=1);

namespace App\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class ContactInquiryData
{
    #[Assert\NotBlank(message: 'Please enter your name.')]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Please enter your email address.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    #[Assert\Length(max: 255)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Please add a subject so Jan knows what this is about.')]
    #[Assert\Length(max: 255)]
    public string $subject = '';

    #[Assert\NotBlank(message: 'Please add a message.')]
    #[Assert\Length(
        min: 10,
        max: 5000,
        minMessage: 'Please write at least {{ limit }} characters so Jan can help.',
        maxMessage: 'Please keep your message under {{ limit }} characters.',
    )]
    public string $message = '';

    /**
     * Honeypot — must stay empty. NO validation constraints; the controller
     * silently rejects any submission where this is non-empty so bots get a
     * fake-success response and don't probe further.
     */
    public string $website = '';

    /**
     * Time-trap — the GET response stamps this with the render timestamp; the
     * POST handler rejects submissions arriving < 2 seconds later (no human
     * fills a 4-field contact form that fast). NO validation constraints;
     * silent reject on missing/too-fast.
     */
    public ?int $renderedAt = null;
}
