<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotifyAdminAboutDomainOwnershipInquiry;
use App\Repository\DomainOwnershipInquiryRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Emails Sendvery support about a domain-ownership claim. The admin reviews
 * manually and, if legitimate, transfers monitored_domain.team_id by hand
 * (psql) — the email contains everything they need to make the call.
 */
#[AsMessageHandler]
final readonly class NotifyAdminAboutDomainOwnershipInquiryHandler
{
    public function __construct(
        private DomainOwnershipInquiryRepository $inquiryRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private ClockInterface $clock,
        #[Autowire(env: 'SENDVERY_ADMIN_EMAIL')]
        private string $adminEmail,
    ) {
    }

    public function __invoke(NotifyAdminAboutDomainOwnershipInquiry $message): void
    {
        $inquiry = $this->inquiryRepository->get($message->inquiryId);

        $html = $this->twig->render('emails/domain_ownership_inquiry.html.twig', [
            'inquiry' => $inquiry,
        ]);

        $email = (new Email())
            ->to($this->adminEmail)
            ->subject(sprintf('[Sendvery] Domain ownership claim: %s', $inquiry->domain))
            ->html($html)
            ->text(sprintf(
                "A user is asking to claim ownership of a domain another team is monitoring.\n\nDomain: %s\nRequested by: %s (team: %s)\nCurrent owner team: %s\nSubmitted at: %s\n\nReview and, if legitimate, transfer ownership:\n  UPDATE monitored_domain SET team_id = '%s' WHERE LOWER(domain) = LOWER('%s');\n",
                $inquiry->domain,
                $inquiry->inquiringUser->email,
                $inquiry->inquiringTeam->name,
                $inquiry->currentOwnerTeam->name,
                $inquiry->createdAt->format('c'),
                $inquiry->inquiringTeam->id->toString(),
                $inquiry->domain,
            ));

        $this->mailer->send($email);

        $inquiry->markNotified($this->clock->now());
    }
}
