<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * Whether a manual "Re-check DNS" may run for a domain right now and, when it
 * may not, how long the caller has to wait (DEC-059 / D14).
 *
 * One shape for both readers: {@see \App\Services\Dns\DomainRecheckThrottle}
 * returns it to the templates (so the button can render itself disabled with a
 * countdown) and to the controller (so a blocked POST can name the same wait in
 * its flash). Without a shared value here the two would drift and the button
 * would promise a different wait than the notice.
 */
final readonly class DomainRecheckAvailability
{
    private function __construct(
        public bool $isAvailable,
        public int $cooldownSeconds,
    ) {
    }

    public static function available(): self
    {
        return new self(true, 0);
    }

    /**
     * A cooldown always reads as at least one second: a sub-second remainder
     * floored to "0s" would tell the user to wait for nothing while the button
     * is still disabled.
     */
    public static function coolingDown(int $cooldownSeconds): self
    {
        return new self(false, max(1, $cooldownSeconds));
    }

    /**
     * Short duration for the disabled button label, e.g. "3m" or "45s".
     * Minutes round UP so the label never promises the button back sooner
     * than the limiter will actually release it.
     */
    public function cooldownLabel(): string
    {
        if ($this->cooldownSeconds >= 60) {
            return (int) ceil($this->cooldownSeconds / 60).'m';
        }

        return $this->cooldownSeconds.'s';
    }
}
