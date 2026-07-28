<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendWeeklyDigest;
use App\Query\GetDigestRecipients;
use App\Repository\TeamRepository;
use App\Services\Digest\WeeklyDigestRenderer;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'sendvery:digest:send-all',
    description: 'Send weekly digest emails to all active teams',
)]
final class SendAllWeeklyDigestsCommand extends Command
{
    /**
     * Hosts that mean "nobody outside this container can click these links".
     * A digest whose every link points here is worse than no digest.
     */
    private const array UNREACHABLE_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /** Project-relative home for `--preview` output when no directory is given. */
    private const string DEFAULT_PREVIEW_DIR = 'var/digest-preview';

    public function __construct(
        private readonly Connection $database,
        private readonly MessageBusInterface $messageBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TeamRepository $teamRepository,
        private readonly WeeklyDigestRenderer $renderer,
        private readonly GetDigestRecipients $recipients,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'team',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict to a single team by slug or UUID — for previewing digest changes against one team.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List the teams that would receive a digest and send nothing.',
            )
            ->addOption(
                'preview',
                null,
                InputOption::VALUE_OPTIONAL,
                'Render each team\'s digest to <dir>/<team-slug>.html and .txt and send nothing. Defaults to '.self::DEFAULT_PREVIEW_DIR.'.',
                // `false` when the flag is absent, `null` when it is passed
                // without a directory — the standard Symfony way to tell those
                // two apart.
                false,
            )
            ->addOption(
                'with-ai',
                null,
                InputOption::VALUE_NONE,
                'Preview only: also generate the AI narration, which costs one real provider call per AI-plan team.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $team = $input->getOption('team');
        $teamFilter = is_string($team) && '' !== $team ? $team : null;
        $preview = $input->getOption('preview');

        // Runs before every branch, preview included. A preview mails nothing,
        // but it is one flag away from a run that does, and a reviewer signing
        // off on an email full of localhost links is precisely how those links
        // reach an inbox.
        if (!$this->reportLinkBaseUrl($io)) {
            return Command::FAILURE;
        }

        $teams = $this->findTeams($teamFilter);

        if ([] === $teams) {
            $io->warning(null !== $teamFilter
                ? sprintf('No onboarded team matches "%s".', $teamFilter)
                : 'No onboarded teams — nothing to send.');

            return Command::SUCCESS;
        }

        if (false !== $preview) {
            return $this->writePreviews(
                $io,
                $teams,
                is_string($preview) && '' !== $preview ? $preview : null,
                true === $input->getOption('with-ai'),
            );
        }

        if ($dryRun) {
            $io->info(sprintf('Dry run — %d team(s) would receive a digest:', count($teams)));
            $io->listing(array_map(static fn (array $row): string => $row['name'], $teams));

            return Command::SUCCESS;
        }

        $io->info(sprintf('Dispatching weekly digest for %d teams.', count($teams)));

        // Per team, because `SendWeeklyDigest` is not routed to a transport:
        // the handler runs inline, so there is no Messenger retry, no failure
        // transport and no isolation between teams. An exception raised while
        // producing one team's digest used to abort the loop, and every team
        // sorted after it silently received nothing that week — one bad row
        // costing everybody their email is the worst possible trade for a
        // weekly job. Same shape as AutoRampDmarcCommand's per-domain sweep,
        // except this one exits non-zero so `lily-cron-run` still pages.
        $failed = 0;

        foreach ($teams as $row) {
            try {
                $this->messageBus->dispatch(new SendWeeklyDigest(
                    teamId: Uuid::fromString($row['id']),
                ));
            } catch (\Throwable $exception) {
                ++$failed;
                \Sentry\captureException($exception);
                $io->warning(sprintf(
                    'Weekly digest failed for %s (%s): %s',
                    $row['name'],
                    $row['id'],
                    $exception->getMessage(),
                ));
            }
        }

        if ($failed > 0) {
            $io->error(sprintf(
                '%d of %d team(s) did not get a digest. The rest were sent.',
                $failed,
                count($teams),
            ));

            return Command::FAILURE;
        }

        $io->success('All weekly digests dispatched.');

        return Command::SUCCESS;
    }

    /**
     * Write both alternatives of each selected team's digest to disk.
     *
     * BOTH parts, always. The HTML is what a reviewer opens in a browser, but a
     * reviewer who can only see the HTML cannot notice that the text/plain
     * alternative has quietly stopped mentioning a section — which is the exact
     * defect that shipped, and the one the parity test now guards.
     *
     * The recipient count travels with each file because a digest that renders
     * beautifully and reaches nobody looks identical on screen to a healthy one.
     *
     * The AI narration is opt-in. `--preview` with no `--team` fans out across
     * every team, and each AI-plan team's narration is a paid provider call —
     * a preview you hesitate to run is a preview nobody runs, and the digest is
     * complete without it. What is skipped is stated in the output, because a
     * preview that quietly omits a section an AI-plan customer would receive is
     * itself misleading.
     *
     * @param list<array{id: string, name: string, slug: string}> $teams
     */
    private function writePreviews(SymfonyStyle $io, array $teams, ?string $directory, bool $withAi): int
    {
        $target = $this->previewDirectory($directory);
        $this->filesystem->mkdir($target);

        $rows = [];

        foreach ($teams as $row) {
            $digest = $this->renderer->render(
                $this->teamRepository->get(Uuid::fromString($row['id'])),
                withAiSummary: $withAi,
            );
            $basename = self::previewBasename($row['slug']);

            $this->filesystem->dumpFile($target.'/'.$basename.'.html', $digest->html);
            $this->filesystem->dumpFile($target.'/'.$basename.'.txt', $digest->text);

            $rows[] = [
                $row['name'],
                (string) count($this->recipients->forTeam($row['id'])),
                $basename.'.html',
                $basename.'.txt',
            ];
        }

        $io->success(sprintf('Rendered %d digest(s). Nothing was sent.', count($teams)));
        $io->table(['Team', 'Subscribers', 'HTML', 'Plain text'], $rows);

        $io->comment($withAi
            ? 'AI narration generated — this run spent one provider call per AI-plan team.'
            : 'AI narration skipped, so an AI-plan team\'s digest is missing that section here. Pass --with-ai to include it (costs one provider call per AI-plan team).');

        $io->comment('Open the HTML in a browser; read the .txt beside it — drift between the two is invisible otherwise.');
        $io->comment('Written to '.$target);

        return Command::SUCCESS;
    }

    private function previewDirectory(?string $directory): string
    {
        if (null === $directory) {
            return $this->projectDir.'/'.self::DEFAULT_PREVIEW_DIR;
        }

        return $this->filesystem->isAbsolutePath($directory)
            ? $directory
            : $this->projectDir.'/'.$directory;
    }

    /**
     * Team slugs are NOT NULL and URL-safe by construction, so this is
     * belt-and-braces: it keeps a hand-edited slug from carrying a path
     * separator or a dot segment into a filename.
     */
    private static function previewBasename(string $slug): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $slug) ?? 'team';
    }

    /**
     * Every link in the digest is generated without an HTTP request, so it comes
     * from `framework.router.default_uri` (env `DEFAULT_URI`). Print the base URL
     * the mail will actually carry, and refuse to send a production digest full
     * of unclickable localhost links — silently emailing customers a dead button
     * is the failure mode this guard exists to prevent.
     */
    private function reportLinkBaseUrl(SymfonyStyle $io): bool
    {
        $dashboardUrl = $this->urlGenerator->generate(
            'dashboard_overview',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $host = parse_url($dashboardUrl, PHP_URL_HOST);

        if ('prod' === $this->environment && in_array($host, self::UNREACHABLE_HOSTS, true)) {
            $io->error([
                sprintf('Refusing to send: digest links would point at %s.', $dashboardUrl),
                'Set DEFAULT_URI to the public base URL (e.g. DEFAULT_URI=https://sendvery.com) on the host or in .env.local, then re-run.',
            ]);

            return false;
        }

        $io->comment(sprintf('Digest links will point at %s', $dashboardUrl));

        return true;
    }

    /**
     * Teams with at least one onboarded member, optionally narrowed to a single
     * team by slug or UUID.
     *
     * @return list<array{id: string, name: string, slug: string}>
     */
    private function findTeams(?string $teamFilter): array
    {
        $sql = 'SELECT DISTINCT t.id::text AS id, t.name AS name, t.slug AS slug
             FROM team t
             JOIN team_membership tm ON tm.team_id = t.id
             JOIN "user" u ON u.id = tm.user_id
             WHERE u.onboarding_completed_at IS NOT NULL';
        $parameters = [];

        if (null !== $teamFilter) {
            // Slug or UUID — matching on the text cast keeps a non-UUID value
            // from blowing up the query with an invalid-input-syntax error.
            $sql .= ' AND (t.slug = :teamFilter OR t.id::text = :teamFilter)';
            $parameters['teamFilter'] = $teamFilter;
        }

        /** @var list<array{id: string, name: string, slug: string}> $rows */
        $rows = $this->database->executeQuery($sql.' ORDER BY t.name', $parameters)->fetchAllAssociative();

        return $rows;
    }
}
