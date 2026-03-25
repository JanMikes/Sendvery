<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

readonly final class DashboardContext
{
    public function __construct(
        private Connection $database,
        private IdentityProvider $identityProvider,
    ) {
    }

    public function getTeamId(): UuidInterface
    {
        $teamId = $this->database->executeQuery(
            'SELECT id FROM team ORDER BY created_at ASC LIMIT 1',
        )->fetchOne();

        if ($teamId !== false) {
            return Uuid::fromString((string) $teamId);
        }

        // Auto-create a personal team for the unsecured dashboard
        $newTeamId = $this->identityProvider->nextIdentity();
        $this->database->executeStatement(
            'INSERT INTO team (id, name, slug, plan, created_at) VALUES (:id, :name, :slug, :plan, :createdAt)',
            [
                'id' => $newTeamId->toString(),
                'name' => 'Personal',
                'slug' => 'personal',
                'plan' => 'free',
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return $newTeamId;
    }
}
