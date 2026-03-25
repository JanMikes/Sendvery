<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\UserFeedback;
use App\Message\SubmitFeedback;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SubmitFeedbackHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private TeamRepository $teamRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SubmitFeedback $message): void
    {
        $user = $this->userRepository->get($message->userId);
        $team = $this->teamRepository->get($message->teamId);

        $feedback = new UserFeedback(
            id: $message->feedbackId,
            user: $user,
            team: $team,
            type: $message->type,
            message: $message->message,
            page: $message->page,
            createdAt: $this->clock->now(),
        );

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        // Notify admin about new feedback
        $adminEmail = (new Email())
            ->to('jan.mikes@sendvery.com')
            ->subject(sprintf('[Feedback] %s from %s', ucfirst(str_replace('_', ' ', $message->type->value)), $user->email))
            ->text(sprintf(
                "New feedback from %s (%s)\n\nType: %s\nPage: %s\n\n%s",
                $user->email,
                $team->name,
                $message->type->value,
                $message->page,
                $message->message,
            ));

        $this->mailer->send($adminEmail);
    }
}
