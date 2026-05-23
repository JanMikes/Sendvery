<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BetaSignup;
use App\Repository\BetaSignupRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class BetaSignupRepositoryTest extends IntegrationTestCase
{
    public function testFindByToken(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(BetaSignupRepository::class);

        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'token-test-'.Uuid::uuid7()->toString().'@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'findabletoken123',
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $found = $repository->findByToken('findabletoken123');
        self::assertNotNull($found);
        self::assertSame($signup->id->toString(), $found->id->toString());
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $repository = $this->getService(BetaSignupRepository::class);

        $found = $repository->findByToken('nonexistent');
        self::assertNull($found);
    }

    public function testFindByEmail(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(BetaSignupRepository::class);

        $email = 'email-test-'.Uuid::uuid7()->toString().'@example.com';
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: $email,
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'emailtoken'.Uuid::uuid7()->toString(),
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $found = $repository->findByEmail($email);
        self::assertNotNull($found);
        self::assertSame($email, $found->email);
    }

    public function testFindByEmailReturnsNullForUnknownEmail(): void
    {
        $repository = $this->getService(BetaSignupRepository::class);

        $found = $repository->findByEmail('nonexistent@example.com');
        self::assertNull($found);
    }

    public function testFindByEmailAndSourceMatchesPairExactly(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(BetaSignupRepository::class);

        // TASK-006: uniqueness moved to (email, source), so a single address
        // can hold one row per source. The repository helper is the
        // dedup-keyed lookup used by NotifyMeAboutToolHandler.
        $email = 'pair-'.Uuid::uuid7()->toString().'@example.com';

        $spfRow = new BetaSignup(
            id: Uuid::uuid7(),
            email: $email,
            domainCount: 1,
            painPoint: 'domain=example.com',
            source: 'spf-result',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'spf-'.Uuid::uuid7()->toString(),
        );
        $spfRow->popEvents();
        $em->persist($spfRow);

        $dkimRow = new BetaSignup(
            id: Uuid::uuid7(),
            email: $email,
            domainCount: 1,
            painPoint: 'domain=example.com',
            source: 'dkim-result',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'dkim-'.Uuid::uuid7()->toString(),
        );
        $dkimRow->popEvents();
        $em->persist($dkimRow);
        $em->flush();

        $foundSpf = $repository->findByEmailAndSource($email, 'spf-result');
        self::assertNotNull($foundSpf);
        self::assertSame($spfRow->id->toString(), $foundSpf->id->toString());

        $foundDkim = $repository->findByEmailAndSource($email, 'dkim-result');
        self::assertNotNull($foundDkim);
        self::assertSame($dkimRow->id->toString(), $foundDkim->id->toString());

        self::assertNull($repository->findByEmailAndSource($email, 'mx-result'));
        self::assertNull($repository->findByEmailAndSource('nobody@example.com', 'spf-result'));
    }
}
