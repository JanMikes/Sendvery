<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that records every DBAL prepare/query/exec call routed through
 * {@see \Doctrine\DBAL\Logging\Middleware}. Tests use it to assert query-count
 * invariants — specifically the TASK-134 batch-resolver regression net that
 * `RuaScenarioResolver::resolveForDomainIds()` issues exactly ONE select
 * against `dns_check_result` regardless of the input size.
 *
 * The DBAL logging middleware emits its SQL via the `{sql}` placeholder in the
 * `$context` array (not interpolated into `$message`), so {@see self::queries()}
 * pulls from there. `flush()` resets the buffer so a test can isolate the
 * subject call from setup / teardown queries the DAMA transaction emits.
 */
final class InMemoryQueryLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Only the prepare/query/exec entries carry a `sql` context key; the
        // begin/commit/rollback messages don't, and we don't want them in the
        // count for the regression assertion.
        if (!isset($context['sql'])) {
            return;
        }

        $sql = $context['sql'];
        if (!is_string($sql)) {
            return;
        }

        $this->records[] = $sql;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->records;
    }

    /**
     * Returns the subset of recorded SQL strings whose normalised form contains
     * the given fragment — useful for the "exactly one select against
     * `dns_check_result`" assertion: the DAMA transaction wrapper, schema
     * introspection, and unrelated queries get filtered out without the test
     * having to reason about the harness's chatter.
     *
     * @return list<string>
     */
    public function queriesContaining(string $fragment): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (string $sql): bool => str_contains($sql, $fragment),
        ));
    }

    public function flush(): void
    {
        $this->records = [];
    }
}
