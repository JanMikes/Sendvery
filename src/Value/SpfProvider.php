<?php

declare(strict_types=1);

namespace App\Value;

/**
 * TASK-155 — One sending-service entry exposed by SpfProviderRegistry.
 *
 * The Stimulus SPF generator consumes the JSON form of this DTO as an
 * array of {key, label, include} objects via the
 * `data-spf-generator-providers-value` attribute. Property order matches
 * the round-8 array shape so `json_encode` produces byte-identical output.
 */
final readonly class SpfProvider implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public string $label,
        public string $include,
    ) {
    }

    /**
     * Emit the same shape Stimulus consumed pre-DTO refactor. Preserves
     * property ORDER so the resulting JSON is byte-identical to the round-8
     * baseline.
     *
     * @return array{key: string, label: string, include: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'include' => $this->include,
        ];
    }
}
