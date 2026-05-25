<?php

declare(strict_types=1);

namespace App\Value;

/**
 * TASK-155 — One mailbox-provider MX preset exposed by MxPresetRegistry.
 *
 * The Stimulus MX generator consumes the JSON form as an array of
 * {key, label, records: [{priority, host}]} objects. Property order
 * matches the round-8 array shape for byte-identical JSON output.
 */
final readonly class MxPreset implements \JsonSerializable
{
    /**
     * @param list<MxPresetRecord> $records
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $records,
    ) {
    }

    /**
     * Emit the same shape Stimulus consumed pre-DTO refactor. Preserves
     * property ORDER so the resulting JSON is byte-identical to the round-8
     * baseline.
     *
     * @return array{key: string, label: string, records: list<array{priority: int, host: string}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'records' => array_map(static fn (MxPresetRecord $r): array => $r->jsonSerialize(), $this->records),
        ];
    }
}
