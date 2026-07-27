<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendWeeklyDigest;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    public function __construct(
        private readonly Connection $database,
        private readonly MessageBusInterface $messageBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');
        $team = $input->getOption('team');
        $teamFilter = is_string($team) && '' !== $team ? $team : null;

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

        if ($dryRun) {
            $io->info(sprintf('Dry run — %d team(s) would receive a digest:', count($teams)));
            $io->listing(array_map(static fn (array $row): string => $row['name'], $teams));

            return Command::SUCCESS;
        }

        $io->info(sprintf('Dispatching weekly digest for %d teams.', count($teams)));

        foreach ($teams as $row) {
            $this->messageBus->dispatch(new SendWeeklyDigest(
                teamId: Uuid::fromString($row['id']),
            ));
        }

        $io->success('All weekly digests dispatched.');

        return Command::SUCCESS;
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
     * @return list<array{id: string, name: string}>
     */
    private function findTeams(?string $teamFilter): array
    {
        $sql = 'SELECT DISTINCT t.id::text AS id, t.name AS name
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

        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->database->executeQuery($sql.' ORDER BY t.name', $parameters)->fetchAllAssociative();

        return $rows;
    }
}
