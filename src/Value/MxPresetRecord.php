<?php

declare(strict_types=1);

namespace App\Value;

/**
 * TASK-155 — One MX record inside an MxPreset.
 */
final readonly class MxPresetRecord implements \JsonSerializable
{
    public function __construct(
        public int $priority,
        public string $host,
    ) {
    }

    /**
     * @return array{priority: int, host: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'priority' => $this->priority,
            'host' => $this->host,
        ];
    }
}
