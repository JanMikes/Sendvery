<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\Dns\FakeAsnResolver;
use App\Services\Dns\FakeDns;
use App\Services\Dns\FakeReverseDnsResolver;
use App\Services\Dns\FakeSmtpProbe;

/**
 * Helper trait for tests that need to script positive DNS, SMTP or reverse-DNS
 * responses. KernelTestCase shuts the kernel down between tests, so each test
 * sees a fresh fake instance — no manual reset needed.
 *
 * Usage:
 *   $this->scriptDns()->withTxt('_dmarc.example.com', 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.com;');
 *   $this->scriptSmtp()->withReachable('192.0.2.10', tlsSupported: true);
 *   $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');
 *   $this->scriptAsn()->withAsn('77.75.76.89', 43037, 'SEZNAM-AS');
 *
 * withHostname() scripts a *genuine* host: the address answers with that PTR
 * hostname and the hostname resolves back to the address, which is what
 * forward-confirmed reverse DNS requires of real mail infrastructure. Use
 * withForgedHostname() for a reverse record that claims a name it cannot back
 * up, and withForwardAddresses() when the hostname's own addresses are not
 * simply the ones that claimed it.
 */
trait ScriptsDnsRecords
{
    protected function scriptReverseDns(): FakeReverseDnsResolver
    {
        $reverseDns = self::getContainer()->get(FakeReverseDnsResolver::class);
        assert($reverseDns instanceof FakeReverseDnsResolver);

        return $reverseDns;
    }

    protected function scriptAsn(): FakeAsnResolver
    {
        $asn = self::getContainer()->get(FakeAsnResolver::class);
        assert($asn instanceof FakeAsnResolver);

        return $asn;
    }

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
