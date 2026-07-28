<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\SmtpProbeResult;

/**
 * Production SmtpProbe. Opens a TCP connection to port 25, expects the
 * 220 greeting banner, then issues EHLO and inspects the response for the
 * STARTTLS capability advertisement.
 */
final readonly class SocketSmtpProbe implements SmtpProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;
    private const int READ_BUFFER = 1024;

    public function probe(string $ip): SmtpProbeResult
    {
        $banner = $this->openAndReadBanner($ip);

        if (null === $banner) {
            return SmtpProbeResult::unreachable();
        }

        return new SmtpProbeResult(
            reachable: true,
            tlsSupported: $this->probeStartTls($ip),
        );
    }

    /**
     * fsockopen() builds `tcp://<hostname>:<port>`, so a bare IPv6 literal
     * turns into an address the parser cannot split — the colons in the literal
     * are indistinguishable from the port separator, and the connection never
     * reaches the host. Measured: `fsockopen('::1', 25)` fails with
     * "getaddrinfo for  failed" (note the empty host) while `fsockopen('[::1]', 25)`
     * genuinely reaches loopback and is refused.
     *
     * That mattered beyond a missing tick: an IPv6-only mail host read as
     * unreachable, and MxChecker's copy for "nothing answered" blames our own
     * egress filtering. We were telling users their perfectly deliverable mail
     * server was fine but our network was restricted, when in fact we had
     * dialled an address that does not exist.
     */
    private function dialAddress(string $ip): string
    {
        return str_contains($ip, ':') ? '['.$ip.']' : $ip;
    }

    private function openAndReadBanner(string $ip): ?string
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->dialAddress($ip), 25, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);

        if (false === $socket) {
            return null;
        }

        $response = @fgets($socket, self::READ_BUFFER);
        fclose($socket);

        if (false === $response || !str_starts_with($response, '220')) {
            return null;
        }

        return $response;
    }

    private function probeStartTls(string $ip): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->dialAddress($ip), 25, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);

        if (false === $socket) {
            return false;
        }

        @fgets($socket, self::READ_BUFFER); // banner

        fwrite($socket, "EHLO sendvery.com\r\n");

        $ehloResponse = '';
        while ($line = @fgets($socket, self::READ_BUFFER)) {
            $ehloResponse .= $line;
            // Multi-line responses have - after status code, last line has space.
            if (isset($line[3]) && '-' !== $line[3]) {
                break;
            }
        }

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return false !== stripos($ehloResponse, 'STARTTLS');
    }
}
