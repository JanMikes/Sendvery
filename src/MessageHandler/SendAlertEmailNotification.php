<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\AlertCreated;
use App\Value\AlertSeverity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class SendAlertEmailNotification
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private Connection $database,
    ) {
    }

    public function __invoke(AlertCreated $event): void
    {
        if (AlertSeverity::Critical !== $event->severity) {
            return;
        }

        $emails = $this->getTeamMemberEmails($event->teamId->toString());

        if ([] === $emails) {
            return;
        }

        $alertUrl = $this->urlGenerator->generate(
            'dashboard_alert_detail',
            ['id' => $event->alertId->toString()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/alert_notification.html.twig', [
            'title' => $event->title,
            'severity' => $event->severity->value,
            'domainName' => $event->domainName,
            'alertUrl' => $alertUrl,
        ]);

        $severityLabel = ucfirst($event->severity->value);
        $subject = "[{$severityLabel}] {$event->title}";

        foreach ($emails as $recipientEmail) {
            $email = (new Email())
                ->to($recipientEmail)
                ->subject($subject)
                ->html($html)
                ->text(sprintf(
                    "%s\n\n%s\n\nView alert: %s\n\n— Sendvery",
                    $subject,
                    $event->title,
                    $alertUrl,
                ));

            $this->mailer->send($email);
        }
    }

    /** @return array<string> */
    private function getTeamMemberEmails(string $teamId): array
    {
        return $this->database->executeQuery(
            'SELECT u.email
             FROM "user" u
             JOIN team_membership tm ON tm.user_id = u.id
             WHERE tm.team_id = :teamId
               AND u.email_alerts_enabled = true',
            ['teamId' => $teamId],
        )->fetchFirstColumn();
    }
}
