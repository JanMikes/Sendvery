<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SendAllWeeklyDigestsCommand;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SendAllWeeklyDigestsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function saysSoInsteadOfPretendingToWorkWhenNoTeamIsOnboarded(): void
    {
        self::bootKernel();
        $tester = $this->tester();

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No onboarded teams', $tester->getDisplay());
    }

    #[Test]
    public function reportsTheBaseUrlEveryDigestLinkWillCarry(): void
    {
        // The digest is generated with no HTTP request, so its links come from
        // the router's configured default URI. Printing it on every run is what
        // makes a misconfigured production host obvious instead of silently
        // emailing customers unclickable localhost buttons.
        self::bootKernel();
        $tester = $this->tester();

        $tester->execute([]);

        self::assertStringContainsString(
            'Digest links will point at https://sendvery.test/app',
            $tester->getDisplay(),
            'The reported base URL must come from DEFAULT_URI, not the router localhost fallback.',
        );
    }

    #[Test]
    public function dispatchesDigestsForActiveTeams(): void
    {
        $this->seedOnboardedTeam();
        $tester = $this->tester();

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('All weekly digests dispatched', $tester->getDisplay());
    }

    #[Test]
    public function dryRunListsTheRecipientTeamsAndSendsNothing(): void
    {
        $team = $this->seedOnboardedTeam();
        $tester = $this->tester();

        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run', $display);
        self::assertStringContainsString($team->name, $display);
        self::assertStringNotContainsString(
            'All weekly digests dispatched',
            $display,
            'A dry run must never claim it sent anything.',
        );
    }

    #[Test]
    public function canBeNarrowedToASingleTeamBySlug(): void
    {
        $wanted = $this->seedOnboardedTeam('Wanted Team');
        $other = $this->seedOnboardedTeam('Other Team');
        $tester = $this->tester();

        $tester->execute(['--team' => $wanted->slug, '--dry-run' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString($wanted->name, $display);
        self::assertStringNotContainsString($other->name, $display);
    }

    #[Test]
    public function anUnknownTeamFilterWarnsRatherThanSendingToEveryone(): void
    {
        $team = $this->seedOnboardedTeam();
        $tester = $this->tester();

        $tester->execute(['--team' => 'no-such-team']);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('No onboarded team matches', $display);
        self::assertStringNotContainsString($team->name, $display);
    }

    private function seedOnboardedTeam(string $name = 'Digest CMD Team'): Team
    {
        $em = $this->getService(EntityManagerInterface::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'digest-cmd-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: $name.' '.substr(Uuid::uuid7()->toString(), 0, 8),
            slug: 'digest-cmd-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        return $team;
    }

    #[Test]
    public function refusesToSendInProductionWhenEveryLinkWouldPointAtAnUnreachableHost(): void
    {
        // The safeguard that stops the localhost-links defect reaching customers
        // again. It can only fire with APP_ENV=prod, which the test kernel never
        // is, so the command is built directly with a prod environment and a
        // router context left on the localhost fallback.
        self::bootKernel();
        $urlGenerator = $this->getService(UrlGeneratorInterface::class);
        $originalHost = $urlGenerator->getContext()->getHost();
        $urlGenerator->getContext()->setHost('localhost');

        try {
            $tester = new CommandTester(new SendAllWeeklyDigestsCommand(
                $this->getService(Connection::class),
                $this->getService(MessageBusInterface::class),
                $urlGenerator,
                'prod',
            ));

            $tester->execute([]);
        } finally {
            $urlGenerator->getContext()->setHost($originalHost);
        }

        self::assertSame(
            Command::FAILURE,
            $tester->getStatusCode(),
            'Sending a digest whose every link points at localhost is worse than sending nothing.',
        );
        self::assertStringContainsString('Refusing to send', $tester->getDisplay());
        self::assertStringContainsString(
            'DEFAULT_URI',
            $tester->getDisplay(),
            'The refusal must name the env var to set, otherwise it is just a dead end.',
        );
    }

    private function tester(): CommandTester
    {
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('sendvery:digest:send-all'));
    }
}
