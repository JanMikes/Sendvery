<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\SmtpProbeResult;

/**
 * Probes an SMTP server: TCP connectivity to port 25 and whether the server
 * advertises STARTTLS in its EHLO response. The production implementation
 * (SocketSmtpProbe) uses fsockopen + EHLO; tests inject FakeSmtpProbe instead
 * so the suite never opens a real TCP socket.
 */
interface SmtpProbe
{
    public function probe(string $ip): SmtpProbeResult;
}
