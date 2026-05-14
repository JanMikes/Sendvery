<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\BetaAccessRequested;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendBetaAccessNotification
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private string $betaRequestsEmail,
    ) {
    }

    public function __invoke(BetaAccessRequested $event): void
    {
        $this->mailer->send(
            (new Email())
                ->to($this->betaRequestsEmail)
                ->replyTo($event->email)
                ->subject(sprintf('Beta access request: %s (%s)', $event->name, $event->requestedPlan->value))
                ->html($this->twig->render('emails/beta_access_notification.html.twig', [
                    'event' => $event,
                ]))
                ->text($this->renderPlainTextNotification($event)),
        );

        $this->mailer->send(
            (new Email())
                ->to($event->email)
                ->subject('We received your Sendvery beta access request')
                ->html($this->twig->render('emails/beta_access_acknowledgement.html.twig', [
                    'name' => $event->name,
                    'plan' => $event->requestedPlan,
                ]))
                ->text($this->renderPlainTextAcknowledgement($event->name)),
        );
    }

    private function renderPlainTextNotification(BetaAccessRequested $event): string
    {
        $lines = [
            'New beta access request',
            '',
            'Name: '.$event->name,
            'Email: '.$event->email,
            'Plan: '.$event->requestedPlan->value,
        ];

        if (null !== $event->company) {
            $lines[] = 'Company: '.$event->company;
        }

        if (null !== $event->domainCount) {
            $lines[] = 'Domains: '.$event->domainCount;
        }

        if (null !== $event->message) {
            $lines[] = '';
            $lines[] = 'Message:';
            $lines[] = $event->message;
        }

        return implode("\n", $lines);
    }

    private function renderPlainTextAcknowledgement(string $name): string
    {
        return sprintf(
            "Hi %s,\n\nThanks for your interest in Sendvery! We received your beta access request and we'll get back to you shortly with next steps.\n\n— Jan, Sendvery",
            $name,
        );
    }
}
