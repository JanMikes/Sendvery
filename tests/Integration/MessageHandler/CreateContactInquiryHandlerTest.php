<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\ContactInquiry;
use App\Message\CreateContactInquiry;
use App\MessageHandler\CreateContactInquiryHandler;
use App\Services\IdentityProvider;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class CreateContactInquiryHandlerTest extends WebTestCase
{
    #[Test]
    public function handlerPersistsInquiryAndSendsEmailToFounder(): void
    {
        self::createClient(); // boots the kernel + the in-memory mail collector

        $handler = $this->getService(CreateContactInquiryHandler::class);
        $identityProvider = $this->getService(IdentityProvider::class);
        $em = $this->getService(EntityManagerInterface::class);

        $inquiryId = $identityProvider->nextIdentity();

        ($handler)(new CreateContactInquiry(
            inquiryId: $inquiryId,
            name: 'Linus Torvalds',
            email: 'linus@example.com',
            subject: 'Self-host on a Pi cluster',
            message: 'Has anyone deployed Sendvery on a Raspberry Pi cluster? Curious about the FrankenPHP footprint.',
            submitterIp: '203.0.113.42',
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64)',
        ));

        $persisted = $em->find(ContactInquiry::class, $inquiryId);
        self::assertNotNull($persisted, 'The handler must persist a contact_inquiry row — the DB row is the audit trail; the email is best-effort delivery.');
        self::assertSame('Linus Torvalds', $persisted->name);
        self::assertSame('linus@example.com', $persisted->email);
        self::assertSame('Self-host on a Pi cluster', $persisted->subject);
        self::assertStringContainsString('Raspberry Pi cluster', $persisted->message);
        self::assertSame('203.0.113.42', $persisted->submitterIp, 'IP address must be persisted so Jan can correlate suspicious submissions later if the rate-limiter is bypassed somehow.');
        self::assertSame('Mozilla/5.0 (X11; Linux x86_64)', $persisted->userAgent, 'User-Agent must be persisted to help triage scripted submissions retrospectively.');

        self::assertEmailCount(1);
        $email = self::getMailerMessages()[0];
        assert($email instanceof \Symfony\Component\Mime\Email);

        self::assertSame('jan.mikes@sendvery.com', $email->getTo()[0]->getAddress(), 'The handler must email the founder address verbatim — not support@, not hello@.');
        self::assertStringContainsString('Self-host on a Pi cluster', (string) $email->getSubject());
        self::assertStringContainsString('Linus Torvalds', (string) ($email->getTextBody() ?? ''));
        self::assertSame('linus@example.com', $email->getReplyTo()[0]->getAddress(), 'Reply-To routes Jan\'s reply straight back to the visitor instead of to himself.');
    }
}
