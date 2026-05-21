<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Dns\FakeDns;
use App\Services\Dns\FakeSmtpProbe;

/**
 * Helper trait for tests that need to script positive DNS or SMTP responses.
 * KernelTestCase shuts the kernel down between tests, so each test sees a fresh
 * FakeDns / FakeSmtpProbe instance — no manual reset needed.
 *
 * Usage:
 *   $this->scriptDns()->withTxt('_dmarc.example.com', 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.com;');
 *   $this->scriptSmtp()->withReachable('192.0.2.10', tlsSupported: true);
 */
trait ScriptsDnsRecords
{
    protected function scriptDns(): FakeDns
    {
        $dns = self::getContainer()->get(FakeDns::class);
        assert($dns instanceof FakeDns);

        return $dns;
    }

    protected function scriptSmtp(): FakeSmtpProbe
    {
        $smtp = self::getContainer()->get(FakeSmtpProbe::class);
        assert($smtp instanceof FakeSmtpProbe);

        return $smtp;
    }
}
