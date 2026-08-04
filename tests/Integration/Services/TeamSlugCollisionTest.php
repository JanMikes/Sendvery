<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\User;
use App\Services\TeamProvisioner;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Team slugs are globally unique in the database, and both places that create a
 * default team built the slug as `<email-domain>-<first 8 chars of user id>`.
 *
 * Those 8 characters look like a discriminator and are not one. A UUID v7 opens
 * with a 48-bit millisecond timestamp, so its first 8 hex digits are the top 32
 * bits of that clock — they only change once every 2^16 ms, roughly every 65
 * seconds. Two people sharing an email domain who signed up inside the same
 * minute therefore got byte-identical slugs, and the second one's very first
 * sign-in died on the unique index with a 500.
 *
 * On a consumer domain that is not a rare race: any two gmail.com signups in the
 * same minute collide.
 */
final class TeamSlugCollisionTest extends WebTestCase
{
    #[Test]
    public function twoPeopleFromOneEmailDomainSigningUpTogetherEachGetTheirOwnTeam(): void
    {
        self::createClient();
        $provisioner = $this->getService(TeamProvisioner::class);

        $first = $this->persistUser('alice@shared-domain.example');
        $second = $this->persistUser('bob@shared-domain.example');

        $firstTeam = $provisioner->provisionForUser($first);
        $secondTeam = $provisioner->provisionForUser($second);

        self::assertNotSame(
            $firstTeam->slug,
            $secondTeam->slug,
            'Two colleagues signing up in the same minute must not be handed the same team slug.',
        );
    }

    #[Test]
    public function theSlugSuffixIsNotJustTheClock(): void
    {
        // The regression guard proper: users created back-to-back land in the
        // same UUID v7 timestamp bucket by construction, so a suffix taken from
        // the leading digits would be identical for both.
        self::createClient();
        $provisioner = $this->getService(TeamProvisioner::class);

        $slugs = [];
        foreach (['a', 'b', 'c'] as $name) {
            $user = $this->persistUser($name.'@same-bucket.example');
            $slugs[] = $provisioner->provisionForUser($user)->slug;
        }

        self::assertCount(3, array_unique($slugs), 'Every provisioned team needs its own slug.');
    }

    #[Test]
    public function theSlugStillLeadsWithTheEmailDomainSoItStaysRecognisable(): void
    {
        self::createClient();
        $provisioner = $this->getService(TeamProvisioner::class);

        $team = $provisioner->provisionForUser($this->persistUser('someone@acme-corp.example'));

        self::assertStringStartsWith('acme-corp-example-', $team->slug);
    }

    private function persistUser(string $email): User
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
