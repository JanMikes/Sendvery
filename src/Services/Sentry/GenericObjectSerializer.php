<?php

declare(strict_types=1);

namespace App\Services\Sentry;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;

/**
 * Serializes FormData / Message objects into rich Sentry context.
 *
 * Wired via `class_serializers` in config/packages/sentry.php so that Sentry events
 * carry actual property values (UUIDs as strings, enums as names, dates formatted) instead
 * of the default opaque object dump. Sensitive properties are redacted.
 */
final class GenericObjectSerializer
{
    private const int MAX_DEPTH = 3;

    /**
     * Property names whose values are replaced with [REDACTED] to keep secrets out of Sentry.
     */
    private const array SENSITIVE_PROPERTIES = [
        'password',
        'plainPassword',
        'currentPassword',
        'newPassword',
        'token',
        'accessToken',
        'refreshToken',
        'apiToken',
        'apiKey',
        'secret',
        'webhookSecret',
        'clientSecret',
        'encryptionKey',
    ];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(object $object): array
    {
        return $this->serializeObject($object, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeObject(object $object, int $depth): array
    {
        $data = [];
        $reflection = new \ReflectionClass($object);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            if (in_array($property->getName(), self::SENSITIVE_PROPERTIES, true)) {
                $data[$property->getName()] = '[REDACTED]';

                continue;
            }

            $data[$property->getName()] = $this->formatValue($property->getValue($object), $depth);
        }

        return $data;
    }

    private function formatValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            null === $value, is_bool($value), is_int($value), is_float($value), is_string($value) => $value,
            $value instanceof UuidInterface => $value->toString(),
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \UnitEnum => $value->name,
            $value instanceof UploadedFile => $value->getClientOriginalName(),
            $value instanceof Envelope => ['message' => $this->formatValue($value->getMessage(), $depth)],
            is_array($value) => array_map(fn (mixed $v): mixed => $this->formatValue($v, $depth), $value),
            is_object($value) && $depth < self::MAX_DEPTH => $this->serializeObject($value, $depth + 1),
            is_object($value) => $this->formatObjectSummary($value),
            default => '...',
        };
    }

    private function formatObjectSummary(object $object): string
    {
        $className = $object::class;

        try {
            $reflection = new \ReflectionClass($object);

            if ($reflection->hasProperty('id')) {
                $idProperty = $reflection->getProperty('id');

                if ($idProperty->isInitialized($object)) {
                    $id = $idProperty->getValue($object);

                    if ($id instanceof UuidInterface) {
                        return $className.'('.$id->toString().')';
                    }
                }
            }
        } catch (\ReflectionException) {
            // fall through to bare class name
        }

        return $className;
    }
}
