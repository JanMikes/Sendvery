<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * One-shot launch-announcement email blast to everyone who left their
 * details via `/request-access` while Sendvery was in fake-door mode
 * (DEC-050). Run once during cutover (Phase 6 of the pricing rollout)
 * after Stripe goes live.
 *
 * Idempotency: stamp `notified_at` on each row as we send so re-running
 * the command (e.g., to retry after partial failure) only emails the
 * untouched leads. The column is added by a migration alongside this
 * command — until then, `--dry-run` is the safe operating mode.
 */
#[AsCommand(
    name: 'sendvery:beta-leads:launch-announce',
    description: 'Send the launch-announcement email to BetaAccessRequest leads collected during the fake-door period.',
)]
final class AnnounceLaunchToBetaLeadsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        #[Autowire(env: 'BETA_REQUESTS_EMAIL')]
        private readonly string $fromAddress,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only contact leads requested on or after this ISO date (e.g. 2026-05-14).')
            ->addOption('coupon', null, InputOption::VALUE_REQUIRED, 'Optional Stripe Promotion Code to include in the email body.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the recipient list without sending anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sinceArg = $input->getOption('since');
        $couponArg = $input->getOption('coupon');
        $dryRun = (bool) $input->getOption('dry-run');

        $since = \is_string($sinceArg) ? new \DateTimeImmutable($sinceArg) : new \DateTimeImmutable('2026-05-14');
        $coupon = \is_string($couponArg) ? $couponArg : null;

        $rows = $this->database
            ->executeQuery(
                'SELECT id, email, name, requested_plan
                 FROM beta_access_request
                 WHERE requested_at >= :since
                 ORDER BY requested_at ASC',
                ['since' => $since->format('Y-m-d H:i:s')],
            )
            ->fetchAllAssociative();

        if ([] === $rows) {
            $io->info('No beta-access leads to notify.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d beta-access lead(s) since %s.', count($rows), $since->format('Y-m-d')));

        if ($dryRun) {
            foreach ($rows as $row) {
                $io->text(sprintf(' • %s (%s, requested %s)', $row['email'], $row['name'], $row['requested_plan']));
            }
            $io->warning('--dry-run: no emails sent.');

            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($rows as $row) {
            $this->mailer->send($this->buildEmail(
                recipient: new Address((string) $row['email'], (string) $row['name']),
                requestedPlan: (string) $row['requested_plan'],
                coupon: $coupon,
            ));
            ++$sent;
        }

        $io->success(sprintf('Sent %d launch-announcement email(s).', $sent));

        return Command::SUCCESS;
    }

    private function buildEmail(Address $recipient, string $requestedPlan, ?string $coupon): Email
    {
        $body = sprintf(
            "Hi %s,\n\nGood news — Sendvery is now open for self-serve sign-up.\n"
            ."When you reached out, you were interested in our %s plan. You can claim it now at %s/pricing.\n",
            $recipient->getName(),
            $requestedPlan,
            rtrim($this->defaultUri, '/'),
        );

        if (null !== $coupon) {
            $body .= sprintf("\nUse coupon code %s at checkout for an extra discount on your first invoice.\n", $coupon);
        }

        $body .= "\nThanks for your patience while we got things ready.\n— The Sendvery team";

        return (new Email())
            ->from(new Address($this->fromAddress, 'Sendvery'))
            ->to($recipient)
            ->subject('Sendvery is open — your beta-access plan is ready')
            ->text($body);
    }
}
