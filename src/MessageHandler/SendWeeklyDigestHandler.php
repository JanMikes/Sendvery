<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendWeeklyDigest;
use App\Query\GetDigestRecipients;
use App\Repository\TeamRepository;
use App\Services\Digest\WeeklyDigestRenderer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SendWeeklyDigestHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
        private WeeklyDigestRenderer $renderer,
        private GetDigestRecipients $recipients,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendWeeklyDigest $message): void
    {
        $recipients = $this->recipients->forTeam($message->teamId->toString());

        // Before rendering, not after: rendering an AI-plan digest spends an AI
        // call, and spending one for a team with no digest subscribers is money
        // for an email nobody asked for.
        if ([] === $recipients) {
            return;
        }

        $team = $this->teamRepository->get($message->teamId);
        $digest = $this->renderer->render($team);

        foreach ($recipients as $recipientEmail) {
            $email = (new Email())
                ->to($recipientEmail)
                ->subject($digest->subject)
                ->html($digest->html)
                ->text($digest->text);

            $this->mailer->send($email);
        }
    }
}
