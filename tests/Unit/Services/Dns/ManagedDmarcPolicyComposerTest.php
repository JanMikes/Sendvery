<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\ManagedDmarcPolicyComposer;
use App\Services\ReportAddressProvider;
use App\Value\DmarcPolicy;
use App\Value\Dns\DmarcRecordSerializer;
use App\Value\Dns\ManagedDmarcPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedDmarcPolicyComposerTest extends TestCase
{
    #[Test]
    public function composesSendveryOnlyRuaWithRelaxedAlignmentAndFo(): void
    {
        $composer = new ManagedDmarcPolicyComposer(
            new ReportAddressProvider('reports@sendvery.test'),
            new DmarcRecordSerializer(),
        );

        $content = $composer->compose(ManagedDmarcPolicy::monitoring());

        self::assertSame('v=DMARC1; p=none; rua=mailto:reports@sendvery.test; adkim=r; aspf=r; fo=1', $content);
    }

    #[Test]
    public function carriesSubdomainPolicyAndCoverageWhenSet(): void
    {
        $composer = new ManagedDmarcPolicyComposer(
            new ReportAddressProvider('reports@sendvery.test'),
            new DmarcRecordSerializer(),
        );

        $content = $composer->compose(new ManagedDmarcPolicy(DmarcPolicy::Reject, DmarcPolicy::Quarantine, 50));

        self::assertSame('v=DMARC1; p=reject; sp=quarantine; rua=mailto:reports@sendvery.test; adkim=r; aspf=r; pct=50; fo=1', $content);
    }
}
