<?php

declare(strict_types=1);

namespace App\Tests;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use Symfony\Component\Clock\MockClock;

abstract class WebTestCase extends SymfonyWebTestCase
{
    protected function createAuthenticatedClient(): KernelBrowser
    {
        return static::createClient();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function getService(string $class): object
    {
        $service = self::getContainer()->get($class);
        assert($service instanceof $class);

        return $service;
    }

    protected function getClock(): MockClock
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        assert($clock instanceof MockClock);

        return $clock;
    }
}
