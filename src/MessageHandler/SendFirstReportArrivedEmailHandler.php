<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\FirstReportArrivedForDomain;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamMembershipRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Sends the "your first DMARC report just arrived" milestone email to every
 * team member who has digest emails enabled. Triggered by the entity event
 * MonitoredDomain emits the first time firstReportAt is set, so we email at
 * most once per domain even on heavy report bursts.
 */
#[AsMessageHandler]
final readonly class SendFirstReportArrivedEmailHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private TeamMembershipRepository $teamMembershipRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(FirstReportArrivedForDomain $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);
        $memberships = $this->teamMembershipRepository->findForTeam($event->teamId);

        if ([] === $memberships) {
            return;
        }

        $dashboardUrl = $this->urlGenerator->generate(
            'dashboard_overview',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/first_report_arrived.html.twig', [
            'domainName' => $domain->domain,
            'reporterOrg' => $event->reporterOrg,
            'dashboardUrl' => $dashboardUrl,
        ]);

        // Respect the same setting weekly digests use — if a user opted out
        // of digest mails, this is the same shape of "milestone" notification.
        $recipients = array_values(array_filter(
            $memberships,
            static fn ($m) => $m->user->emailDigestEnabled,
        ));
        if ([] === $recipients) {
            return;
        }

        $email = (new Email())
            ->subject(sprintf('Your first DMARC report just arrived for %s', $domain->domain))
            ->html($html)
            ->text(sprintf(
                "Great news! Your first DMARC aggregate report for %s just arrived (from %s). Check it out:\n%s\n\n— The Sendvery Team",
                $domain->domain,
                $event->reporterOrg,
                $dashboardUrl,
            ));

        foreach ($recipients as $membership) {
            $email->addTo($membership->user->email);
        }

        $this->mailer->send($email);
    }
}
