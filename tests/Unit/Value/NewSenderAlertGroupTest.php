<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\NewSenderAlertGroup;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewSenderAlertGroupTest extends TestCase
{
    #[Test]
    public function readsAsAnActionableNamePlusTheVolumeBehindIt(): void
    {
        $group = new NewSenderAlertGroup(
            identityKey: 'cloud-sec-av.com',
            label: 'cloud-sec-av.com',
            role: SenderRole::Forwarder,
            messageCount: 3,
            sourceIps: ['52.212.19.177', '15.222.110.90'],
        );

        self::assertSame('cloud-sec-av.com (3 messages)', $group->describe());
    }

    #[Test]
    public function countsASingleMessageInTheSingular(): void
    {
        $group = new NewSenderAlertGroup(
            identityKey: 'strangehost.example',
            label: 'strangehost.example',
            role: SenderRole::Unknown,
            messageCount: 1,
            sourceIps: ['203.0.113.200'],
        );

        self::assertSame('strangehost.example (1 message)', $group->describe());
    }

    #[Test]
    public function keepsTheUnderlyingAddressesForWhoeverNeedsToInvestigate(): void
    {
        $group = new NewSenderAlertGroup(
            identityKey: 'seznam.cz',
            label: 'Seznam',
            role: SenderRole::Esp,
            messageCount: 22,
            sourceIps: ['77.75.76.89', '2a02:598:1::908'],
        );

        self::assertSame(
            [
                'identity' => 'seznam.cz',
                'label' => 'Seznam',
                'role' => 'esp',
                'messages' => 22,
                'source_ips' => ['77.75.76.89', '2a02:598:1::908'],
            ],
            $group->toAlertData(),
            'The alert names the sender, but support still needs the addresses that made it up.',
        );
    }
}
