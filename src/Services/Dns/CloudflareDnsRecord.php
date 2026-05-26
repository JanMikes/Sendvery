<?php

declare(strict_types=1);

namespace App\Services\Dns;

final readonly class CloudflareDnsRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $content,
        public string $comment,
        public string $createdOn,
    ) {
    }

    /** @param array{id: string, name: string, content: string, comment?: string, created_on?: string} $data */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            content: $data['content'],
            comment: $data['comment'] ?? '',
            createdOn: $data['created_on'] ?? '',
        );
    }
}
