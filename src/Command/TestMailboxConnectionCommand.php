<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MailboxConnection;
use App\Entity\Team;
use App\Services\CredentialEncryptor;
use App\Services\Mail\MailClient;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sendvery:mailbox:test',
    description: 'Test an IMAP mailbox connection',
)]
final class TestMailboxConnectionCommand extends Command
{
    public function __construct(
        private readonly MailClient $mailClient,
        private readonly CredentialEncryptor $encryptor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('host', InputArgument::REQUIRED, 'IMAP server hostname')
            ->addArgument('port', InputArgument::REQUIRED, 'IMAP server port')
            ->addArgument('username', InputArgument::REQUIRED, 'IMAP username')
            ->addArgument('password', InputArgument::REQUIRED, 'IMAP password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $host = $input->getArgument('host');
        $port = (int) $input->getArgument('port');
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        assert(is_string($host) && is_string($username) && is_string($password));

        $io->info(sprintf('Testing connection to %s:%d...', $host, $port));

        // Build a temporary MailboxConnection for testing
        // We need a fake team — this command is for dev debugging only
        $encryptedUsername = $this->encryptor->encrypt($username);
        $encryptedPassword = $this->encryptor->encrypt($password);

        $fakeTeam = new Team(
            id: Uuid::uuid7(),
            name: 'CLI Test',
            slug: 'cli-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $fakeTeam,
            type: MailboxType::ImapUser,
            host: $host,
            port: $port,
            encryptedUsername: $encryptedUsername,
            encryptedPassword: $encryptedPassword,
            encryption: 993 === $port ? MailboxEncryption::Ssl : MailboxEncryption::StartTls,
            createdAt: new \DateTimeImmutable(),
        );

        $result = $this->mailClient->testConnection($connection);

        if ($result->success) {
            $io->success(sprintf('Connection successful! Messages in mailbox: %d', $result->mailboxCount));

            return Command::SUCCESS;
        }

        $io->error(sprintf('Connection failed: %s', $result->error ?? 'Unknown error'));

        return Command::FAILURE;
    }
}
