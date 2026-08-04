<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use Doctrine\DBAL\Driver\Exception as DriverException;

/**
 * The driver-level error PostgreSQL raises for a unique-index collision. DBAL's
 * own exceptions wrap one of these, so a faithful double has to as well.
 *
 * @see DuplicateKeyCommandBus
 */
final class PostgresUniqueViolation extends \RuntimeException implements DriverException
{
    public function getSQLState(): string
    {
        return '23505'; // unique_violation
    }
}
