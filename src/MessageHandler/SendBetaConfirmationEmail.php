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
            ->subject('Confirm your Sendvery beta signup')
            ->html($html)
            ->text(sprintf(
                "Hi!\n\nThanks for signing up for the Sendvery beta.\n\nPlease confirm your email by visiting:\n%s\n\nWhat happens next?\nWe'll notify you as soon as Sendvery is ready for beta testers. You'll be among the first to try it.\n\n— The Sendvery Team",
                $confirmUrl,
            ));

        $this->mailer->send($email);
    }
}
