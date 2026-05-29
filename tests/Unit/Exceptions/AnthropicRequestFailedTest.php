<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\AnthropicRequestFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnthropicRequestFailedTest extends TestCase
{
    #[Test]
    public function defaultsToRetryableSoTransientFailuresAreRetriedByMessenger(): void
    {
        $exception = new AnthropicRequestFailed('upstream 529');

        self::assertTrue($exception->retryable);
        self::assertSame('upstream 529', $exception->getMessage());
    }

    #[Test]
    public function permanentFailuresAreMarkedNonRetryableAndCarryTheCause(): void
    {
        $previous = new \RuntimeException('400 bad request');
        $exception = new AnthropicRequestFailed('bad request', retryable: false, previous: $previous);

        self::assertFalse($exception->retryable);
        self::assertSame($previous, $exception->getPrevious());
    }
}
