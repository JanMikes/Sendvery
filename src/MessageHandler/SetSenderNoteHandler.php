<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SetSenderNote;
use App\Repository\KnownSenderRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SetSenderNoteHandler
{
    private const int NOTE_MAX_LENGTH = 10000;

    public function __construct(
        private KnownSenderRepository $knownSenderRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SetSenderNote $message): void
    {
        $sender = $this->knownSenderRepository->findForTeam($message->senderId, $message->teamId);

        if (null === $sender) {
            // Forged or stale id — silently no-op. Controller already
            // surfaces 404 for the direct path; this is defense-in-depth.
            return;
        }

        $actor = $this->userRepository->get($message->actorUserId);

        // Normalize empty string → null so the template's "no note yet"
        // path picks it up cleanly. Truncate runaway payloads — UI also
        // enforces maxlength on the textarea.
        $note = $message->note;
        if (null !== $note) {
            $note = trim($note);
            if ('' === $note) {
                $note = null;
            } elseif (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
                $note = mb_substr($note, 0, self::NOTE_MAX_LENGTH);
            }
        }

        $sender->setNotes($note, $actor, $this->clock->now());
    }
}
