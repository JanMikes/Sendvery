<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A command bus that answers every dispatch the way the real one does when two
 * requests race each other to insert the same unique key: the handler's flush
 * fails inside the doctrine_transaction middleware, and Messenger re-throws it
 * wrapped in a {@see HandlerFailedException}.
 *
 * That collision is unreachable from a transactional test — DAMA keeps the
 * suite inside one transaction, so a genuinely concurrent committed insert
 * cannot exist. Substituting the bus is the only way to exercise the recovery
 * path for real rather than excusing it with a coverage-ignore comment.
 */
final class DuplicateKeyCommandBus implements MessageBusInterface
{
    /**
     * @param array<mixed> $stamps
     */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $collision = new UniqueConstraintViolationException(new PostgresUniqueViolation(), null);

        throw new HandlerFailedException(new Envelope($message), [$collision]);
    }
}
