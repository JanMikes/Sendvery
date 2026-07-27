<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 §3.3, §3.6)
 */
final class SenderRoleTest extends TestCase
{
    /**
     * @return array<string, array{SenderRole, string}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'own relay' => [SenderRole::OwnRelay, 'own_relay'],
            'esp' => [SenderRole::Esp, 'esp'],
            'forwarder' => [SenderRole::Forwarder, 'forwarder'],
            'unknown' => [SenderRole::Unknown, 'unknown'],
            'suspicious' => [SenderRole::Suspicious, 'suspicious'],
        ];
    }

    #[Test]
    #[DataProvider('storedValueProvider')]
    public function eachRoleKeepsAStableStoredValue(SenderRole $role, string $expected): void
    {
        self::assertSame(
            $expected,
            $role->value,
            'Roles are persisted in sender_identity; changing a stored value silently reclassifies every existing row.',
        );
    }

    #[Test]
    public function everyRoleOffersAHumanLabel(): void
    {
        foreach (SenderRole::cases() as $role) {
            self::assertNotSame('', $role->label(), sprintf('Role "%s" must be presentable to a user.', $role->value));
        }
    }

    /**
     * @return array<string, array{SenderRole}>
     */
    public static function normalMailFlowProvider(): array
    {
        return [
            'own relay' => [SenderRole::OwnRelay],
            'recognised provider' => [SenderRole::Esp],
            'forwarder' => [SenderRole::Forwarder],
        ];
    }

    #[Test]
    #[DataProvider('normalMailFlowProvider')]
    public function explainedSendersAreDigestMaterialNotAlerts(SenderRole $role): void
    {
        self::assertFalse(
            $role->warrantsAlert(),
            'A sender we can explain is normal mail flow and must never raise a warning.',
        );
    }

    /**
     * @return array<string, array{SenderRole}>
     */
    public static function needsReviewProvider(): array
    {
        return [
            'unidentified' => [SenderRole::Unknown],
            'looks like abuse' => [SenderRole::Suspicious],
        ];
    }

    #[Test]
    #[DataProvider('needsReviewProvider')]
    public function unexplainedSendersAreWorthInterruptingTheUserFor(SenderRole $role): void
    {
        self::assertTrue(
            $role->warrantsAlert(),
            'A sender nothing could explain is the only kind worth an alert.',
        );
    }
}
