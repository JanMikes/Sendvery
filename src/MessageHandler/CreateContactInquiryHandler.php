<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ContactInquiry;
use App\Message\CreateContactInquiry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class CreateContactInquiryHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(CreateContactInquiry $message): void
    {
        $inquiry = new ContactInquiry(
            id: $message->inquiryId,
            name: $message->name,
            email: $message->email,
            subject: $message->subject,
            message: $message->message,
            submittedAt: $this->clock->now(),
            submitterIp: $message->submitterIp,
            userAgent: $message->userAgent,
        );

        $this->entityManager->persist($inquiry);

        // Notify the founder. Same Mailer transport as SubmitFeedbackHandler
        // (magic-link auth + weekly digest already share this transport — we
        // deliberately do NOT introduce a second mail provider).
        $founderEmail = (new Email())
            ->to('jan.mikes@sendvery.com')
            ->replyTo($message->email)
            ->subject(sprintf('[Contact] %s — %s', $message->subject, $message->name))
            ->text(sprintf(
                "New contact inquiry via /about/contact\n\nFrom: %s <%s>\nSubject: %s\nSubmitted: %s\nIP: %s\nUser-Agent: %s\n\nMessage:\n%s\n",
                $message->name,
                $message->email,
                $message->subject,
                $inquiry->submittedAt->format(\DateTimeInterface::ATOM),
                $message->submitterIp ?? '(unknown)',
                $message->userAgent ?? '(unknown)',
                $message->message,
            ));

        $this->mailer->send($founderEmail);
    }
}
