<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\BetaSignup;
use App\Message\RegisterBetaSignup;
use App\MessageHandler\RegisterBetaSignupHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RegisterBetaSignupHandlerTest extends IntegrationTestCase
{
    public function testCreatesSignupEntity(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $signupId = Uuid::uuid7();
        $command = new RegisterBetaSignup(
            signupId: $signupId,
            email: 'beta-' . $signupId->toString() . '@example.com',
            domainCount: 5,
            painPoint: 'DNS is hard',
            source: 'homepage',
        );

        $handler = self::getContainer()->get(RegisterBetaSignupHandler::class);
        assert($handler instanceof RegisterBetaSignupHandler);
        $handler($command);
        $em->flush();

        $signup = $em->find(BetaSignup::class, $signupId);
        self::assertNotNull($signup);
        self::assertStringContainsString('@example.com', $signup->email);
        self::assertSame(5, $signup->domainCount);
        self::assertSame('DNS is hard', $signup->painPoint);
        self::assertSame('homepage', $signup->source);
        self::assertNull($signup->confirmedAt);
        self::assertNotEmpty($signup->confirmationToken);
        self::assertSame(64, strlen($signup->confirmationToken));
    }

    public function testCreatesSignupWithNullOptionalFields(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $signupId = Uuid::uuid7();
        $command = new RegisterBetaSignup(
            signupId: $signupId,
            email: 'minimal-' . $signupId->toString() . '@example.com',
            domainCount: null,
            painPoint: null,
            source: 'beta-page',
        );

        $handler = self::getContainer()->get(RegisterBetaSignupHandler::class);
        assert($handler instanceof RegisterBetaSignupHandler);
        $handler($command);
        $em->flush();

        $signup = $em->find(BetaSignup::class, $signupId);
        self::assertNotNull($signup);
        self::assertNull($signup->domainCount);
        self::assertNull($signup->painPoint);
    }
}
