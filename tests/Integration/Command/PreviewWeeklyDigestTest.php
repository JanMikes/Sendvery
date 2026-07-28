<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SendAllWeeklyDigestsCommand;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Query\GetDigestRecipients;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\Digest\WeeklyDigestRenderer;
use App\Tests\Fixtures\Persona;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\FixedWeeklyDigestAiInsightsService;
use App\Tests\TestSupport\FullWeeklyDigestFixture;
use App\Value\TeamRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Looking at the digest before customers do.
 *
 * Every digest defect this quarter shipped because nobody had seen a rendered
 * one — the surface with the widest reach had the narrowest feedback loop. A
 * preview closes it: the same renderer that would have mailed the digest writes
 * both alternatives to disk instead, so a reviewer opens the real email in a
 * browser rather than a reconstruction of it.
 *
 * Both parts are written, deliberately. A reviewer who can only see the HTML
 * cannot notice that the plain-text alternative has gone quiet — which is the
 * defect the parity test exists for, and the one that actually happened.
 */
final class PreviewWeeklyDigestTest extends IntegrationTestCase
{
    private ?string $previewDir = null;

    private FixedWeeklyDigestAiInsightsService $ai;

    protected function tearDown(): void
    {
        if (null !== $this->previewDir) {
            (new Filesystem())->remove($this->previewDir);
            $this->previewDir = null;
        }

        parent::tearDown();
    }

    #[Test]
    public function writesBothAlternativesOfTheRealDigestToDiskAndSendsNothing(): void
    {
        $persona = $this->seedTeam();
        $tester = $this->tester();

        $tester->execute(['--preview' => $this->previewDirectory(), '--team' => $persona->team->slug]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $html = $this->previewDirectory().'/'.$persona->team->slug.'.html';
        $text = $this->previewDirectory().'/'.$persona->team->slug.'.txt';

        self::assertFileExists($html, 'The HTML alternative is what a reviewer opens in a browser.');
        self::assertFileExists($text, 'Without the text alternative on disk, nobody can review it for drift.');

        self::assertStringContainsString('Domain breakdown', (string) file_get_contents($html));
        self::assertStringContainsString(FullWeeklyDigestFixture::DOMAIN, (string) file_get_contents($text));

        self::assertSame([], self::getMailerMessages(), 'A preview must never send anything.');
        self::assertStringNotContainsString('All weekly digests dispatched', $tester->getDisplay());
    }

    #[Test]
    public function tellsTheReviewerWhereTheFilesAreAndHowManyPeopleWouldHaveReceivedIt(): void
    {
        // A digest can render beautifully and reach nobody — every member with
        // the digest switched off looks identical on screen to a healthy team.
        // So the count must be of *subscribers*, not of members: this team has
        // two people and only one of them asked for the email.
        $persona = $this->seedTeam();
        $this->addMemberWhoTurnedTheDigestOff($persona);
        $tester = $this->tester();

        $tester->execute(['--preview' => $this->previewDirectory(), '--team' => $persona->team->slug]);

        $display = $tester->getDisplay();
        self::assertStringContainsString($persona->team->slug.'.html', $display);
        self::assertStringContainsString($persona->team->slug.'.txt', $display);

        preg_match('/'.preg_quote(FullWeeklyDigestFixture::TEAM_NAME, '/').'\s+(\d+)\s/', $display, $subscribers);
        self::assertArrayHasKey(1, $subscribers, 'The table must carry a subscriber count for the team.');
        self::assertSame(
            '1',
            $subscribers[1],
            'Two members, one of whom switched the digest off — the preview must report who would actually get it.',
        );
    }

    private function addMemberWhoTurnedTheDigestOff(Persona $persona): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'digest-off-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable('-30 days'),
            onboardingCompletedAt: new \DateTimeImmutable('-30 days'),
            emailDigestEnabled: false,
        );
        $user->popEvents();
        $em->persist($user);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $persona->team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable('-30 days'),
        ));
        $em->flush();
    }

    #[Test]
    public function costsNothingToRunUnlessTheAiNarrationIsAskedForExplicitly(): void
    {
        // `--preview` with no `--team` fans out across every team, and each
        // AI-plan team's narration is a paid provider call. A review tool you
        // hesitate to run is a review tool nobody runs, so the default is the
        // free one — and the digest is complete without the narration anyway.
        $persona = $this->seedTeam();
        $tester = $this->tester();

        $tester->execute(['--preview' => $this->previewDirectory(), '--team' => $persona->team->slug]);

        self::assertSame(
            0,
            $this->ai->weeklyDigestCalls,
            'A plain preview must not spend a provider call.',
        );
        self::assertStringNotContainsString(
            FixedWeeklyDigestAiInsightsService::SUMMARY,
            (string) file_get_contents($this->previewDirectory().'/'.$persona->team->slug.'.html'),
        );
        self::assertStringContainsString(
            '--with-ai',
            $tester->getDisplay(),
            'A preview that quietly drops a section an AI-plan customer would see is misleading; '
            .'it has to say what is missing and how to get it.',
        );

        $tester->execute(['--preview' => $this->previewDirectory(), '--team' => $persona->team->slug, '--with-ai' => true]);

        self::assertSame(1, $this->ai->weeklyDigestCalls);
        self::assertStringContainsString(
            FixedWeeklyDigestAiInsightsService::SUMMARY,
            (string) file_get_contents($this->previewDirectory().'/'.$persona->team->slug.'.html'),
        );
        self::assertStringContainsString(
            FixedWeeklyDigestAiInsightsService::SUMMARY,
            (string) file_get_contents($this->previewDirectory().'/'.$persona->team->slug.'.txt'),
        );
    }

    #[Test]
    public function needsNoArgumentsToLandSomewhereObviousInsideTheProject(): void
    {
        // `--preview` on its own is what a human types. If that had to be
        // spelled with an absolute path nobody would use it, and the digest
        // would go back to being reviewed by not being reviewed.
        $persona = $this->seedTeam();
        $projectDir = self::$kernel?->getProjectDir() ?? '';
        $tester = $this->tester();

        $tester->execute(['--preview' => null, '--team' => $persona->team->slug]);

        $written = [
            $projectDir.'/var/digest-preview/'.$persona->team->slug.'.html',
            $projectDir.'/var/digest-preview/'.$persona->team->slug.'.txt',
        ];

        // A relative directory is resolved against the project too, so the path
        // printed back is one the reader can actually open.
        $relative = 'var/digest-preview-relative-'.Uuid::uuid7()->toString();
        $tester->execute(['--preview' => $relative, '--team' => $persona->team->slug]);
        $written[] = $projectDir.'/'.$relative.'/'.$persona->team->slug.'.html';
        $written[] = $projectDir.'/'.$relative.'/'.$persona->team->slug.'.txt';

        try {
            foreach ($written as $path) {
                self::assertFileExists($path);
            }
        } finally {
            (new Filesystem())->remove(array_merge($written, [$projectDir.'/'.$relative]));
        }
    }

    #[Test]
    public function stillRefusesInProductionWhenEveryLinkWouldPointAtAnUnreachableHost(): void
    {
        // A preview writes files instead of sending, but it must not become a
        // way around the guard that keeps unclickable localhost links out of
        // customers' inboxes — the same run would otherwise be one flag away
        // from mailing them.
        $this->seedTeam();
        $urlGenerator = $this->getService(UrlGeneratorInterface::class);
        $originalHost = $urlGenerator->getContext()->getHost();
        $urlGenerator->getContext()->setHost('localhost');

        try {
            $tester = new CommandTester($this->productionCommand($urlGenerator));
            $tester->execute(['--preview' => $this->previewDirectory()]);
        } finally {
            $urlGenerator->getContext()->setHost($originalHost);
        }

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Refusing to send', $tester->getDisplay());
        self::assertDirectoryDoesNotExist(
            $this->previewDirectory(),
            'A refused run must not leave a preview behind pretending it worked.',
        );
    }

    /**
     * The guard only fires with APP_ENV=prod, which the test kernel never is,
     * so the command is built directly with a production environment.
     */
    private function productionCommand(UrlGeneratorInterface $urlGenerator): SendAllWeeklyDigestsCommand
    {
        assert(null !== self::$kernel);

        return new SendAllWeeklyDigestsCommand(
            $this->getService(Connection::class),
            $this->getService(MessageBusInterface::class),
            $urlGenerator,
            $this->getService(TeamRepository::class),
            $this->getService(WeeklyDigestRenderer::class),
            $this->getService(GetDigestRecipients::class),
            new Filesystem(),
            'prod',
            self::$kernel->getProjectDir(),
        );
    }

    private function seedTeam(): Persona
    {
        $this->ai = new FixedWeeklyDigestAiInsightsService();
        self::getContainer()->set(AiInsightsService::class, $this->ai);

        return (new FullWeeklyDigestFixture($this->getService(EntityManagerInterface::class)))
            ->seed(
                'digest-preview-'.Uuid::uuid7()->toString().'@example.com',
                'digest-preview-'.Uuid::uuid7()->toString(),
            );
    }

    private function previewDirectory(): string
    {
        return $this->previewDir ??= sys_get_temp_dir().'/sendvery-digest-preview-'.Uuid::uuid7()->toString();
    }

    private function tester(): CommandTester
    {
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('sendvery:digest:send-all'));
    }
}
