<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\CredentialEncryptor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sendvery:credentials:migrate',
    description: 'Re-encrypt existing credentials from legacy sodium to Halite format',
)]
final class MigrateCredentialsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly CredentialEncryptor $encryptor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connections = $this->database->executeQuery(
            'SELECT id, encrypted_username, encrypted_password FROM mailbox_connection',
        )->fetchAllAssociative();

        $migrated = 0;

        foreach ($connections as $row) {
            $reEncryptedPassword = $this->encryptor->reEncrypt($row['encrypted_password']);
            $reEncryptedUsername = $this->encryptor->reEncrypt($row['encrypted_username']);

            if (null === $reEncryptedPassword && null === $reEncryptedUsername) {
                continue;
            }

            $this->database->executeStatement(
                'UPDATE mailbox_connection SET encrypted_password = :password, encrypted_username = :username WHERE id = :id',
                [
                    'password' => $reEncryptedPassword ?? $row['encrypted_password'],
                    'username' => $reEncryptedUsername ?? $row['encrypted_username'],
                    'id' => $row['id'],
                ],
            );

            ++$migrated;
        }

        $io->success(sprintf(
            'Migration complete. %d connection(s) re-encrypted, %d already in Halite format.',
            $migrated,
            count($connections) - $migrated,
        ));

        return Command::SUCCESS;
    }
}
