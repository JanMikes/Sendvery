<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MailboxConnection;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\ProcessReceivedReportEmail;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `sendvery:reports:reprocess` re-runs the CENTRAL inbox's stage-two pipeline,
 * and CLAUDE.md tells operators to reach for it after a parser fix. It used to
 * be safe by construction: only `central_inbox` envelopes existed.
 *
 * BYO mailboxes now write envelopes too, including `failed` ones — exactly the
 * rows the ops workflow says to reprocess. Running them through
 * `ProcessReceivedReportEmail` would route by the report's policy domain
 * through DmarcReportRouter instead of the connection's bound monitoredDomain
 * (a different, possibly quarantining destination), and would then call
 * `moveProcessed()` with null IMAP uids against the CENTRAL Seznam inbox,
 * matching on Message-ID. Given how the headerless-EML/Seznam quirks were
 * found, this is precisely the cross-pipeline surprise not to ship.
 */
final class ReprocessEnvelopeRefusesForeignPipelinesTest extends IntegrationTestCase
{
    #[Test]
    public function itRefusesAnEnvelopeThatDidNotArriveThroughTheCentralInbox(): void
    {
        $envelope = $this->persistEnvelope(ReportSource::ByoMailbox);

        $tester = $this->commandTester();
        $tester->execute(['envelope-id' => $envelope->id->toString()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('byo_mailbox', $tester->getDisplay(), 'The operator has to be told which pipeline the envelope actually came from.');
        self::assertSame([], $this->dispatchedReprocessMessages(), 'Nothing may be queued: this envelope belongs to a pipeline with different routing and a different mail server.');
    }

    #[Test]
    public function itStillReprocessesACentralInboxEnvelope(): void
    {
        $envelope = $this->persistEnvelope(ReportSource::CentralInbox);

        $tester = $this->commandTester();
        $tester->execute(['envelope-id' => $envelope->id->toString()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $dispatched = $this->dispatchedReprocessMessages();
        self::assertCount(1, $dispatched, 'The ops loop this command exists for must keep working.');
        self::assertTrue($envelope->id->equals($dispatched[0]->envelopeId));
    }

    /** @return list<ProcessReceivedReportEmail> */
    private function dispatchedReprocessMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        /** @var list<ProcessReceivedReportEmail> $messages */
        $messages = array_values(array_filter(
            array_map(static fn (object $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof ProcessReceivedReportEmail,
        ));

        return $messages;
    }

    private function persistEnvelope(ReportSource $source): ReceivedReportEmail
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Reprocess Source',
            slug: 'reprocess-source-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.test.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $connection->popEvents();
        $em->persist($connection);

        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: $source,
            messageId: '<reprocess-'.Uuid::uuid7()->toString().'@example.com>',
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'DMARC Aggregate Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 4,
            rawEml: 'body',
            mailboxConnection: ReportSource::ByoMailbox === $source ? $connection : null,
        );
        $envelope->markFailed('parser blew up', new \DateTimeImmutable());
        $em->persist($envelope);
        $em->flush();

        return $envelope;
    }

    private function commandTester(): CommandTester
    {
        self::bootKernel();
        \assert(null !== self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('sendvery:reports:reprocess'));
    }
}
