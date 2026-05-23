<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\OgImage;

use App\Entity\DomainHealthSnapshot;
use App\Exceptions\OgImageContentNotFoundException;
use App\Services\OgImage\HealthOgImageContentResolver;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class HealthOgImageContentResolverTest extends WebTestCase
{
    #[Test]
    public function resolvesHealthSnapshotByShareHash(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(HealthOgImageContentResolver::class);
        assert($resolver instanceof HealthOgImageContentResolver);

        $hash = $this->persistSnapshot('A', 95);

        $content = $resolver->resolve($hash);

        self::assertStringContainsString('test.example', $content->title);
        self::assertSame('Grade A', $content->badgeText);
        self::assertStringContainsString('95/100', $content->subtitle);
        // Grade A → green-600. Verifies the grade→colour mapping so the
        // share card actually communicates the grade visually.
        self::assertSame(22, $content->badgeRgbR);
        self::assertSame(163, $content->badgeRgbG);
        self::assertSame(74, $content->badgeRgbB);
    }

    #[Test]
    public function fallsBackToRedForFailingGrades(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(HealthOgImageContentResolver::class);
        assert($resolver instanceof HealthOgImageContentResolver);

        $hash = $this->persistSnapshot('F', 25);

        $content = $resolver->resolve($hash);

        self::assertSame('Grade F', $content->badgeText);
        // Red — the failure case must look visually different so a Grade F
        // share doesn't look like a Grade A in a Twitter unfurl.
        self::assertSame(220, $content->badgeRgbR);
        self::assertSame(38, $content->badgeRgbG);
        self::assertSame(38, $content->badgeRgbB);
    }

    #[Test]
    public function throwsForUnknownShareHash(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(HealthOgImageContentResolver::class);
        assert($resolver instanceof HealthOgImageContentResolver);

        $this->expectException(OgImageContentNotFoundException::class);
        $resolver->resolve('hash-that-was-never-issued');
    }

    private function persistSnapshot(string $grade, int $score): string
    {
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $hash = bin2hex(random_bytes(16));
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: $grade,
            score: $score,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 100,
            blacklistScore: 90,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: $hash,
        ));
        $em->flush();

        return $hash;
    }
}
