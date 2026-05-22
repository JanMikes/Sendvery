<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendTeamInvitationEmail;
use App\Repository\TeamInvitationRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendTeamInvitationEmailHandler
{
    public function __construct(
        private TeamInvitationRepository $invitationRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function __invoke(SendTeamInvitationEmail $message): void
    {
        $invitation = $this->invitationRepository->get($message->invitationId);

        $acceptUrl = $this->urlGenerator->generate(
            'team_accept_invitation',
            ['token' => $invitation->invitationToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $teamName = $invitation->team->name;
        $inviterEmail = $invitation->invitedBy->email;

        $html = $this->twig->render('emails/team_invitation.html.twig', [
            'acceptUrl' => $acceptUrl,
            'teamName' => $teamName,
            'inviterEmail' => $inviterEmail,
            'invitedEmail' => $invitation->invitedEmail,
            'expiresAt' => $invitation->expiresAt,
            'role' => $invitation->role->value,
        ]);

        $email = (new Email())
            ->to($invitation->invitedEmail)
            ->subject(sprintf('%s invited you to join %s on Sendvery', $inviterEmail, $teamName))
            ->html($html)
            ->text(sprintf(
                "Hi,\n\n%s invited you to join their Sendvery team (%s) to collaborate on DMARC monitoring.\n\nAccept the invitation here:\n%s\n\nThis link expires on %s.\n\nIf you weren't expecting this, you can safely ignore the email.\n\n— The Sendvery Team",
                $inviterEmail,
                $teamName,
                $acceptUrl,
                $invitation->expiresAt->format('Y-m-d'),
            ));

        $this->mailer->send($email);
    }
}
