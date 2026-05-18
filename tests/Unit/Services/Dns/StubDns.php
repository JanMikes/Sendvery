<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use Spatie\Dns\Dns;
use Spatie\Dns\Records\CNAME;
use Spatie\Dns\Records\MX;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Records\TXT;
use Spatie\Dns\Support\Domain;

/**
 * In-memory stub that lets tests script DNS responses per (name, type) without
 * hitting the network. Mirrors just enough of Spatie\Dns\Dns::getRecords() to
 * satisfy our checkers.
 */
final class StubDns extends Dns
{
    /** @var array<string, array<string, list<Record>>> */
    private array $records = [];

    /** @var array<string, array<string, true>> */
    private array $throws = [];

    public function withCname(string $name, string $target): self
    {
        $this->records[$name]['CNAME'][] = CNAME::make([
            'host' => $name,
            'ttl' => 60,
            'class' => 'IN',
            'type' => 'CNAME',
            'target' => $target,
        ]);

        return $this;
    }

    public function withTxt(string $name, string $value): self
    {
        $this->records[$name]['TXT'][] = TXT::make([
            'host' => $name,
            'ttl' => 60,
            'class' => 'IN',
            'type' => 'TXT',
            'txt' => $value,
        ]);

        return $this;
    }

    public function withMx(string $name, string $target, int $priority = 10): self
    {
        $this->records[$name]['MX'][] = MX::make([
            'host' => $name,
            'ttl' => 60,
            'class' => 'IN',
            'type' => 'MX',
            'pri' => $priority,
            'target' => $target,
        ]);

        return $this;
    }

    public function throwOn(string $name, string $type): self
    {
        $this->throws[$name][strtoupper($type)] = true;

        return $this;
    }

    /**
     * @param Domain|string            $search
     * @param int|string|array<string> $types
     *
     * @return array<int, Record>
     */
    public function getRecords($search, $types = DNS_ALL): array
    {
        $name = (string) $search;
        $type = is_string($types) ? strtoupper($types) : 'ANY';

        if (isset($this->throws[$name][$type])) {
            throw new \RuntimeException('stub: simulated DNS failure');
        }

        return $this->records[$name][$type] ?? [];
    }
}
