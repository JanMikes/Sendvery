<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Spatie\Dns\Dns;
use Spatie\Dns\Records\A;
use Spatie\Dns\Records\CNAME;
use Spatie\Dns\Records\MX;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Records\TXT;
use Spatie\Dns\Support\Domain;

/**
 * Test-environment replacement for Spatie\Dns\Dns. Scriptable in-memory store so
 * tests can describe what DNS "returns" for each (name, type) without touching
 * the network.
 *
 * Aliased via config/services.php under when@test as the default Spatie\Dns\Dns,
 * so every checker — and every code path that flows through CheckDomainDnsHandler
 * — sees scripted records instead of live ones. Tests that want a specific
 * scenario fetch this instance via the container and chain ->withTxt() / ->withMx()
 * before exercising the SUT. reset() is called between tests by ScriptsDnsRecords.
 */
final class FakeDns extends Dns
{
    /** @var array<string, array<string, list<Record>>> */
    private array $records = [];

    /** @var array<string, array<string, true>> */
    private array $throws = [];

    public function withA(string $name, string $ip): self
    {
        $this->records[$name]['A'][] = A::make([
            'host' => $name,
            'ttl' => 60,
            'class' => 'IN',
            'type' => 'A',
            'ip' => $ip,
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

    public function reset(): void
    {
        $this->records = [];
        $this->throws = [];
    }

    /**
     * @param Domain|string            $search
     * @param int|string|array<string> $types
     *
     * @return array<int, Record>
     */
    public function getRecords($search = '', $types = DNS_ALL): array
    {
        $name = (string) $search;
        $type = is_string($types) ? strtoupper($types) : 'ANY';

        if (isset($this->throws[$name][$type])) {
            throw new \RuntimeException('FakeDns: simulated DNS failure for '.$name.' '.$type);
        }

        return $this->records[$name][$type] ?? [];
    }
}
