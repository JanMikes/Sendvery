<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeDnswlResolver;
use App\Value\Dns\DnswlListing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeDnswlResolverTest extends TestCase
{
    #[Test]
    public function answersOnlyWithListingsATestScripted(): void
    {
        $resolver = new FakeDnswlResolver()->withListing('40.93.13.60', DnswlListing::TRUST_HIGH);
        $listing = $resolver->lookup('40.93.13.60');

        self::assertNotNull($listing);
        self::assertSame(DnswlListing::TRUST_HIGH, $listing->trustLevel);
        self::assertNull(
            $resolver->lookup('198.51.100.5'),
            'Anything unscripted must look like an address nobody vouched for, never a real lookup.',
        );
    }

    #[Test]
    public function countsItsLookupsSoCachingCanBeProved(): void
    {
        $resolver = new FakeDnswlResolver()->withListing('40.93.13.60', DnswlListing::TRUST_HIGH);

        $resolver->lookup('40.93.13.60');
        $resolver->lookup('198.51.100.5');

        self::assertSame(2, $resolver->lookupCount(), 'An address nobody listed still cost a lookup.');
    }

    #[Test]
    public function forgetsEverythingWhenReset(): void
    {
        $resolver = new FakeDnswlResolver()->withListing('40.93.13.60', DnswlListing::TRUST_HIGH);
        $resolver->lookup('40.93.13.60');

        $resolver->reset();

        self::assertNull($resolver->lookup('40.93.13.60'));
        self::assertSame(1, $resolver->lookupCount(), 'The counter restarts with the script; the call just made is the first of the new run.');
    }
}
