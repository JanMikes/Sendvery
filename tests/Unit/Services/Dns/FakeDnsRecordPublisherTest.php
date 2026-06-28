<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeDnsRecordPublisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeDnsRecordPublisherTest extends TestCase
{
    #[Test]
    public function publishesStoresAndReportsPolicyContent(): void
    {
        $fake = new FakeDnsRecordPublisher();

        $id = $fake->publishPolicyRecord('acme.org', 'v=DMARC1; p=quarantine');

        self::assertNotNull($id);
        self::assertTrue($fake->policyRecordExists('acme.org'));
        self::assertSame('v=DMARC1; p=quarantine', $fake->getPublishedPolicyContent('acme.org'));

        $record = $fake->findPolicyRecord('acme.org');
        self::assertNotNull($record);
        self::assertSame('v=DMARC1; p=quarantine', $record->content);
    }

    #[Test]
    public function removeClearsThePolicyRecord(): void
    {
        $fake = new FakeDnsRecordPublisher();
        $fake->publishPolicyRecord('acme.org', 'v=DMARC1; p=none');

        self::assertTrue($fake->removePolicyRecord('acme.org'));
        self::assertFalse($fake->policyRecordExists('acme.org'));
        self::assertNull($fake->findPolicyRecord('acme.org'));
        self::assertNull($fake->getPublishedPolicyContent('acme.org'));
    }

    #[Test]
    public function honoursSimulatedFailure(): void
    {
        $fake = new FakeDnsRecordPublisher();
        $fake->simulateFailure();

        self::assertNull($fake->publishPolicyRecord('acme.org', 'v=DMARC1; p=none'));
        self::assertFalse($fake->removePolicyRecord('acme.org'));

        $fake->simulateSuccess();
        self::assertNotNull($fake->publishPolicyRecord('acme.org', 'v=DMARC1; p=none'));
    }
}
