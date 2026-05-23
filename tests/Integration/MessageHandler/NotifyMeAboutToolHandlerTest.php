<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\BetaSignup;
use App\Message\NotifyMeAboutTool;
use App\MessageHandler\NotifyMeAboutToolHandler;
use App\Repository\BetaSignupRepository;
use App\Tests\IntegrationTestCase;
use App\Value\ToolNotifySource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class NotifyMeAboutToolHandlerTest extends IntegrationTestCase
{
    public function testPersistsBetaSignupWithDomainEncodedInPainPoint(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(NotifyMeAboutToolHandler::class);

        $signupId = Uuid::uuid7();
        $email = 'first-'.$signupId->toString().'@example.com';

        $handler(new NotifyMeAboutTool(
            signupId: $signupId,
            email: $email,
            domain: 'example.com',
            source: ToolNotifySource::Spf,
        ));
        $em->flush();

        $signup = $em->find(BetaSignup::class, $signupId);
        self::assertNotNull($signup);
        self::assertSame($email, $signup->email);
        self::assertSame('spf-result', $signup->source);
        self::assertSame('domain=example.com', $signup->painPoint);
        self::assertSame(1, $signup->domainCount);
        self::assertNull($signup->confirmedAt);
        self::assertNotEmpty($signup->confirmationToken);
    }

    public function testIdempotentForSameEmailAndSource(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(NotifyMeAboutToolHandler::class);
        $repository = $this->getService(BetaSignupRepository::class);

        $email = 'dedup-'.Uuid::uuid7()->toString().'@example.com';

        $firstId = Uuid::uuid7();
        $handler(new NotifyMeAboutTool(
            signupId: $firstId,
            email: $email,
            domain: 'example.com',
            source: ToolNotifySource::Dkim,
        ));
        $em->flush();

        $secondId = Uuid::uuid7();
        $handler(new NotifyMeAboutTool(
            signupId: $secondId,
            email: $email,
            domain: 'example.com',
            source: ToolNotifySource::Dkim,
        ));
        $em->flush();

        $rows = $em->getRepository(BetaSignup::class)->findBy([
            'email' => $email,
            'source' => 'dkim-result',
        ]);
        self::assertCount(1, $rows);
        // The first call wins — the second is a silent no-op so we don't
        // re-issue the confirmation email.
        self::assertSame($firstId->toString(), $rows[0]->id->toString());

        $found = $repository->findByEmailAndSource($email, 'dkim-result');
        self::assertNotNull($found);
        self::assertSame($firstId->toString(), $found->id->toString());
    }

    public function testSameEmailDifferentSourceCreatesAdditionalRow(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(NotifyMeAboutToolHandler::class);

        $email = 'multi-'.Uuid::uuid7()->toString().'@example.com';

        $handler(new NotifyMeAboutTool(
            signupId: Uuid::uuid7(),
            email: $email,
            domain: 'example.com',
            source: ToolNotifySource::Spf,
        ));
        $em->flush();

        $handler(new NotifyMeAboutTool(
            signupId: Uuid::uuid7(),
            email: $email,
            domain: 'example.com',
            source: ToolNotifySource::Dmarc,
        ));
        $em->flush();

        $rows = $em->getRepository(BetaSignup::class)->findBy(['email' => $email]);
        self::assertCount(2, $rows);

        $sources = array_map(static fn (BetaSignup $row): string => $row->source, $rows);
        sort($sources);
        self::assertSame(['dmarc-result', 'spf-result'], $sources);
    }
}
