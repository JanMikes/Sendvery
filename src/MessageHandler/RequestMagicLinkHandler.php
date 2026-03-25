<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MagicLinkToken;
use App\Message\RequestMagicLink;
use App\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class RequestMagicLinkHandler
{
    private const int TOKEN_EXPIRY_MINUTES = 15;
    private const int MAX_REQUESTS_PER_HOUR = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private MagicLinkTokenRepository $magicLinkTokenRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestMagicLink $message): void
    {
        $now = $this->clock->now();
        $oneHourAgo = $now->modify('-1 hour');

        $recentCount = $this->magicLinkTokenRepository->countRecentByEmail(
            $message->email,
            $oneHourAgo,
        );

        if ($recentCount >= self::MAX_REQUESTS_PER_HOUR) {
            return;
        }

        $user = $this->userRepository->findByEmail($message->email);
        $token = bin2hex(random_bytes(32));
        $expiresAt = $now->modify('+'.self::TOKEN_EXPIRY_MINUTES.' minutes');

        $magicLinkToken = new MagicLinkToken(
            id: $message->tokenId,
            email: $message->email,
            token: $token,
            expiresAt: $expiresAt,
            createdAt: $now,
            user: $user,
        );

        $this->entityManager->persist($magicLinkToken);
        $this->entityManager->flush();

        $verifyUrl = $this->urlGenerator->generate(
            'auth_verify_magic_link',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new Email())
            ->to($message->email)
            ->subject('Sign in to Sendvery')
            ->html($this->renderEmailHtml($verifyUrl))
            ->text($this->renderEmailText($verifyUrl));

        $this->mailer->send($email);
    }

    private function renderEmailHtml(string $verifyUrl): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 40px 20px; color: #1a1a2e;">
            <div style="text-align: center; margin-bottom: 32px;">
                <h1 style="font-size: 24px; font-weight: 700; color: #6366f1; margin: 0;">Sendvery</h1>
            </div>
            <div style="background: #f8fafc; border-radius: 12px; padding: 32px; text-align: center;">
                <h2 style="font-size: 20px; margin: 0 0 16px;">Sign in to Sendvery</h2>
                <p style="color: #64748b; margin: 0 0 24px;">Click the button below to sign in. This link expires in 15 minutes.</p>
                <a href="{$verifyUrl}" style="display: inline-block; background: #6366f1; color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; font-size: 16px;">Sign in</a>
            </div>
            <p style="text-align: center; color: #94a3b8; font-size: 13px; margin-top: 24px;">
                If you didn't request this link, you can safely ignore this email.
            </p>
        </body>
        </html>
        HTML;
    }

    private function renderEmailText(string $verifyUrl): string
    {
        return <<<TEXT
        Sign in to Sendvery

        Click this link to sign in: {$verifyUrl}

        This link expires in 15 minutes.

        If you didn't request this link, you can safely ignore this email.
        TEXT;
    }
}
