<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Sends the informational managed-DMARC transactional emails (DEC-058) to a
 * team's alert-subscribed members. The Critical touchpoints (regression,
 * dangling) instead go through the existing critical-alert email path; these
 * informational ones need a dedicated sender because that path drops non-Critical.
 */
final readonly class ManagedDmarcMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Connection $database,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function send(
        string $teamId,
        UuidInterface $domainId,
        string $domainName,
        string $pretitle,
        string $subject,
        string $heading,
        string $body,
        string $ctaLabel,
    ): void {
        $recipients = $this->teamMemberEmails($teamId);
        if ([] === $recipients) {
            return;
        }

        $ctaUrl = $this->urlGenerator->generate(
            'dashboard_domain_detail',
            ['id' => $domainId->toString()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/managed_dmarc_notification.html.twig', [
            'subject' => $subject,
            'pretitle' => $pretitle,
            'heading' => $heading,
            'body' => $body,
            'ctaLabel' => $ctaLabel,
            'ctaUrl' => $ctaUrl,
            'domainName' => $domainName,
        ]);

        $text = sprintf("%s\n\n%s\n\n%s: %s\n\n— Sendvery", $heading, $body, $ctaLabel, $ctaUrl);

        foreach ($recipients as $recipient) {
            $this->mailer->send(
                (new Email())
                    ->to($recipient)
                    ->subject($subject)
                    ->html($html)
                    ->text($text),
            );
        }
    }

    /** @return list<string> */
    private function teamMemberEmails(string $teamId): array
    {
        /** @var list<string> $emails */
        $emails = $this->database->executeQuery(
            'SELECT u.email
             FROM "user" u
             JOIN team_membership tm ON tm.user_id = u.id
             WHERE tm.team_id = :teamId
               AND u.email_alerts_enabled = true',
            ['teamId' => $teamId],
        )->fetchFirstColumn();

        return $emails;
    }
}
