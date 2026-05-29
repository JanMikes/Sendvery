<?php

declare(strict_types=1);

namespace App\Services\Ai\Security;

/**
 * Sanitizes attacker-controlled strings before they enter an AI prompt. DMARC
 * reports are sent by outside parties, so org names, hostnames, domains and IPs
 * are untrusted and may carry prompt-injection payloads.
 *
 * Defenses applied:
 *  - Strip control + format characters (`\p{C}`: C0/C1 controls, zero-width,
 *    bidi overrides) that can hide or reorder injected instructions.
 *  - Neutralize `<`/`>` so a value can't forge the `</report_facts>` fence or
 *    inject a fake tag.
 *  - Collapse whitespace runs and hard-cap length so a value can't pad the
 *    prompt or smuggle multiline instructions.
 *
 * This is one layer; the others are the system-prompt instruction to treat
 * fenced data as data, the forced tool schema, and output validation.
 */
final readonly class UntrustedDataSanitizer
{
    public function sanitize(string $value, int $maxLength = 120): string
    {
        // Drop anything in Unicode category "Other" (controls, format chars,
        // zero-width, bidi). preg_replace returns null on malformed UTF-8.
        $clean = preg_replace('/\p{C}/u', '', $value) ?? '';

        // Can't forge the fence or a tag once angle brackets are gone.
        $clean = str_replace(['<', '>'], ['(', ')'], $clean);

        // Single-line, single-spaced.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);

        if (mb_strlen($clean) > $maxLength) {
            $clean = rtrim(mb_substr($clean, 0, $maxLength - 1)).'…';
        }

        return '' === $clean ? '(unknown)' : $clean;
    }

    /**
     * IPs are echoed back to the customer, so a non-IP here is either corrupt
     * data or an injection attempt — collapse it to a marker rather than pass
     * it through.
     */
    public function sanitizeIp(string $ip): string
    {
        return false !== filter_var(trim($ip), FILTER_VALIDATE_IP) ? trim($ip) : '(invalid IP)';
    }
}
