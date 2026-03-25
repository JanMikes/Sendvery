<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BetaInvitation;
use App\Message\InviteBetaUser;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class InviteBetaUserHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(InviteBetaUser $message): void
    {
        $now = $this->clock->now();
        $invitedBy = null !== $message->invitedById
            ? $this->userRepository->get($message->invitedById)
            : null;

        $token = bin2hex(random_bytes(32));

        $invitation = new BetaInvitation(
            id: $message->invitationId,
            email: $message->email,
            invitationToken: $token,
            sentAt: $now,
            expiresAt: $now->modify('+7 days'),
            invitedBy: $invitedBy,
        );

        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $acceptUrl = $this->urlGenerator->generate(
            'auth_accept_invitation',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/beta_invitation.html.twig', [
            'acceptUrl' => $acceptUrl,
            'invitedByEmail' => $invitedBy?->email,
        ]);

        $email = (new Email())
            ->to($message->email)
            ->subject("You're invited to Sendvery Beta!")
            ->html($html)
            ->text(sprintf(
                "You're invited to Sendvery Beta!\n\n%s\n\nAccept your invitation: %s\n\nThis link expires in 7 days.\n\n— Sendvery",
                null !== $invitedBy ? "Invited by {$invitedBy->email}" : 'You have been invited to try Sendvery',
                $acceptUrl,
            ));

        $this->mailer->send($email);
    }
}
