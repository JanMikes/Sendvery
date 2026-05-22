<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendOwnershipTransferNotifications;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendOwnershipTransferNotificationsHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(SendOwnershipTransferNotifications $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $newOwner = $this->userRepository->get($message->newOwnerUserId);
        $previousOwner = $this->userRepository->get($message->previousOwnerUserId);

        $teamSettingsUrl = $this->urlGenerator->generate(
            'team_settings',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->sendToNewOwner($team->name, $newOwner->email, $previousOwner->email, $teamSettingsUrl);
        $this->sendToPreviousOwner($team->name, $previousOwner->email, $newOwner->email, $teamSettingsUrl);
    }

    private function sendToNewOwner(string $teamName, string $recipient, string $previousOwnerEmail, string $teamSettingsUrl): void
    {
        $html = $this->twig->render('emails/team_ownership_transferred_new_owner.html.twig', [
            'teamName' => $teamName,
            'previousOwnerEmail' => $previousOwnerEmail,
            'teamSettingsUrl' => $teamSettingsUrl,
        ]);

        $email = (new Email())
            ->to($recipient)
            ->subject(sprintf('You\'re now the Owner of %s on Sendvery', $teamName))
            ->html($html)
            ->text(sprintf(
                "Hi,\n\n%s transferred ownership of the Sendvery team \"%s\" to you. You can now manage members, change roles, and adjust team settings.\n\nManage the team: %s\n\n— The Sendvery Team",
                $previousOwnerEmail,
                $teamName,
                $teamSettingsUrl,
            ));

        $this->mailer->send($email);
    }

    private function sendToPreviousOwner(string $teamName, string $recipient, string $newOwnerEmail, string $teamSettingsUrl): void
    {
        $html = $this->twig->render('emails/team_ownership_transferred_previous_owner.html.twig', [
            'teamName' => $teamName,
            'newOwnerEmail' => $newOwnerEmail,
            'teamSettingsUrl' => $teamSettingsUrl,
        ]);

        $email = (new Email())
            ->to($recipient)
            ->subject(sprintf('Ownership of %s on Sendvery has been transferred', $teamName))
            ->html($html)
            ->text(sprintf(
                "Hi,\n\nYou transferred ownership of the Sendvery team \"%s\" to %s. You're now an Admin — you can still invite, remove, and manage members, but ownership-only actions belong to %s.\n\nTeam settings: %s\n\nIf this wasn't you, contact support immediately.\n\n— The Sendvery Team",
                $teamName,
                $newOwnerEmail,
                $newOwnerEmail,
                $teamSettingsUrl,
            ));

        $this->mailer->send($email);
    }
}
