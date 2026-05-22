<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\ReceivedReportEmail;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine hydrates BYTEA columns as PHP resources, so rawEmlBytes() takes a
 * different branch after a fresh load than right after construction. We exercise
 * the stream path here so the accessor doesn't silently break in production.
 */
final class ReceivedReportEmailReloadTest extends IntegrationTestCase
{
    public function testReloadedRawEmlReadsAsBytes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $identityProvider = $this->getService(IdentityProvider::class);

        $original = new ReceivedReportEmail(
            id: $identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<reload-1@example.com>',
            fromAddress: 'dmarc@example.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 13,
            rawEml: "BINARY\x00BYTES",
        );

        $em->persist($original);
        $em->flush();
        $em->clear();

        $reloaded = $em->find(ReceivedReportEmail::class, $original->id);

        self::assertNotNull($reloaded);
        self::assertSame("BINARY\x00BYTES", $reloaded->rawEmlBytes());
        // Calling twice still works because rawEmlBytes() rewinds the stream.
        self::assertSame("BINARY\x00BYTES", $reloaded->rawEmlBytes());
    }
}
