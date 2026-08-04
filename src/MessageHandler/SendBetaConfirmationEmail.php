<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\BetaSignupCreated;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendBetaConfirmationEmail
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function __invoke(BetaSignupCreated $event): void
    {
        $confirmUrl = $this->urlGenerator->generate(
            'beta_confirm',
            ['token' => $event->confirmationToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/beta_confirmation.html.twig', [
            'confirmUrl' => $confirmUrl,
            'email' => $event->email,
        ]);

        $email = (new Email())
            ->to($event->email)
            ->subject('Confirm your email address')
            ->html($html)
            ->text(sprintf(
                "Hi!\n\nYou asked us to keep you posted about your domain's email setup.\n\nPlease confirm your email by visiting:\n%s\n\nThat's it — you're on the list, and no account is needed. If you'd rather monitor your domain properly, you can create a free account any time.\n\n— The Sendvery Team",
                $confirmUrl,
            ));

        $this->mailer->send($email);
    }
}
