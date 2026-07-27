<?php

declare(strict_types=1);

namespace App\Value\Reports;

use App\Value\AuthResult;
use App\Value\DmarcAlignment;

/**
 * Explains, in plain language, why one row of a DMARC aggregate report passed
 * or failed — so a user never needs an AI plan (or a copy of RFC 7489) to act
 * on a failing sender.
 *
 * IMPORTANT about the inputs: the `dkim_result` / `spf_result` we store on
 * `dmarc_record` are read from the reporter's `<policy_evaluated>` block, which
 * per RFC 7489 §7.2 is the *DMARC* result — the receiver has already applied
 * identifier alignment when producing it. So `pass` there means "authenticated
 * AND aligned", and the overall verdict is simply "did DKIM or SPF pass",
 * matching the pass-rate arithmetic on the reports list.
 *
 * What this class adds is the *why*: `<auth_results>` also names the DKIM `d=`
 * domain, its selector and the SPF envelope domain, so a failure can be told
 * apart as (a) nothing was signed / no SPF identity, (b) the identifier belongs
 * to somebody else and therefore cannot align, or (c) the identifier does align
 * but the cryptographic/IP check itself failed. Those three failures have three
 * completely different fixes.
 */
final readonly class RecordAlignmentVerdict
{
    /**
     * @param list<string> $explanations plain-language sentences, most important first
     */
    private function __construct(
        public AuthMechanismOutcome $dkim,
        public AuthMechanismOutcome $spf,
        public bool $dmarcPass,
        public string $headline,
        public string $tone,
        public array $explanations,
    ) {
    }

    public static function evaluate(
        string $headerFrom,
        string $dkimResult,
        ?string $dkimDomain,
        ?string $dkimSelector,
        string $spfResult,
        ?string $spfDomain,
        string $policyAdkim,
        string $policyAspf,
    ): self {
        $adkim = DmarcAlignment::tryFrom($policyAdkim) ?? DmarcAlignment::Relaxed;
        $aspf = DmarcAlignment::tryFrom($policyAspf) ?? DmarcAlignment::Relaxed;

        $dkimPassed = AuthResult::Pass->value === $dkimResult;
        $spfPassed = AuthResult::Pass->value === $spfResult;

        $dkim = self::outcomeFor($dkimPassed, $dkimDomain, $headerFrom, $adkim);
        $spf = self::outcomeFor($spfPassed, $spfDomain, $headerFrom, $aspf);

        $dmarcPass = $dkim->contributesToDmarcPass() || $spf->contributesToDmarcPass();

        $explanations = [
            $dmarcPass
                ? 'DMARC passes: at least one of DKIM or SPF both authenticated and aligned with the From domain.'
                : 'DMARC fails: neither DKIM nor SPF managed to both authenticate and align with the From domain, so receivers apply your published policy to this mail.',
            self::explainDkim($dkim, $headerFrom, $dkimDomain, $dkimSelector, $adkim),
            self::explainSpf($spf, $headerFrom, $spfDomain, $aspf),
        ];

        foreach (self::reporterDisagreements($dkimPassed, $dkimDomain, $spfPassed, $spfDomain, $headerFrom, $adkim, $aspf) as $note) {
            $explanations[] = $note;
        }

        return new self(
            dkim: $dkim,
            spf: $spf,
            dmarcPass: $dmarcPass,
            headline: $dmarcPass ? 'DMARC pass' : 'DMARC fail',
            tone: $dmarcPass ? 'success' : 'error',
            explanations: $explanations,
        );
    }

    private static function outcomeFor(
        bool $passed,
        ?string $identifier,
        string $headerFrom,
        DmarcAlignment $mode,
    ): AuthMechanismOutcome {
        if ($passed) {
            return AuthMechanismOutcome::Aligned;
        }

        if (null === $identifier || '' === $identifier) {
            return AuthMechanismOutcome::NotPresent;
        }

        return DomainAlignment::isAligned($identifier, $headerFrom, $mode)
            ? AuthMechanismOutcome::AuthenticationFailed
            : AuthMechanismOutcome::Misaligned;
    }

    private static function explainDkim(
        AuthMechanismOutcome $outcome,
        string $headerFrom,
        ?string $dkimDomain,
        ?string $dkimSelector,
        DmarcAlignment $mode,
    ): string {
        $selector = null !== $dkimSelector && '' !== $dkimSelector
            ? sprintf(' (selector s=%s)', $dkimSelector)
            : '';

        return match ($outcome) {
            AuthMechanismOutcome::Aligned => null !== $dkimDomain && '' !== $dkimDomain
                ? sprintf('DKIM passed — the message was signed by d=%s%s, which aligns with your From domain %s.', $dkimDomain, $selector, $headerFrom)
                : sprintf('DKIM passed and aligned with your From domain %s (this report did not name the signing domain).', $headerFrom),
            AuthMechanismOutcome::Misaligned => sprintf(
                'DKIM did not align — the message was signed by d=%s%s, but the visible From address uses %s, and your policy asks for %s. Mail sent through a provider that signs with its own domain needs a DKIM key published under your domain (usually a CNAME the provider hands you) before it can align.',
                (string) $dkimDomain,
                $selector,
                $headerFrom,
                self::requirement($mode),
            ),
            AuthMechanismOutcome::AuthenticationFailed => sprintf(
                'DKIM aligned but did not verify — the signature named d=%s%s, which does line up with %s, yet the check failed. Usually the public key is missing or was rotated out of DNS, or something modified the message in transit (a mailing list footer, for example).',
                (string) $dkimDomain,
                $selector,
                $headerFrom,
            ),
            AuthMechanismOutcome::NotPresent => sprintf(
                'No DKIM signature was reported for these messages, so DKIM cannot pass for %s. Turn on DKIM signing wherever this mail is sent from.',
                $headerFrom,
            ),
        };
    }

    private static function explainSpf(
        AuthMechanismOutcome $outcome,
        string $headerFrom,
        ?string $spfDomain,
        DmarcAlignment $mode,
    ): string {
        return match ($outcome) {
            AuthMechanismOutcome::Aligned => null !== $spfDomain && '' !== $spfDomain
                ? sprintf('SPF passed — the sending IP is authorised for %s, which aligns with your From domain %s.', $spfDomain, $headerFrom)
                : sprintf('SPF passed and aligned with your From domain %s.', $headerFrom),
            AuthMechanismOutcome::Misaligned => sprintf(
                'SPF did not align — the envelope sender (return-path) domain was %s while the visible From address uses %s, and your policy asks for %s. That is normal for forwarded mail and for providers that bounce through their own domain: those messages have to pass on DKIM instead.',
                (string) $spfDomain,
                $headerFrom,
                self::requirement($mode),
            ),
            AuthMechanismOutcome::AuthenticationFailed => sprintf(
                'SPF did not pass — %s does align with your From domain, but this sending IP is not listed in its SPF record. Add the sender to your SPF record if you recognise it; if you do not, this is mail being sent as you.',
                (string) $spfDomain,
            ),
            AuthMechanismOutcome::NotPresent => 'No SPF result was reported for these messages, so SPF cannot pass here.',
        };
    }

    /**
     * A reporter can mark a mechanism as passing while the identifier this
     * report names does not look aligned to us — most often because the message
     * carried several DKIM signatures and an aggregate report names only one, or
     * because the receiver's organisational-domain table differs from ours. Say
     * so rather than quietly contradicting the pass/fail badge.
     *
     * @return list<string>
     */
    private static function reporterDisagreements(
        bool $dkimPassed,
        ?string $dkimDomain,
        bool $spfPassed,
        ?string $spfDomain,
        string $headerFrom,
        DmarcAlignment $adkim,
        DmarcAlignment $aspf,
    ): array {
        $notes = [];

        if ($dkimPassed && null !== $dkimDomain && '' !== $dkimDomain && !DomainAlignment::isAligned($dkimDomain, $headerFrom, $adkim)) {
            $notes[] = sprintf(
                'Worth knowing: the reporter counted DKIM as passing even though the signing domain named here (%s) does not obviously align with %s. Messages often carry more than one DKIM signature and an aggregate report names only one of them.',
                $dkimDomain,
                $headerFrom,
            );
        }

        if ($spfPassed && null !== $spfDomain && '' !== $spfDomain && !DomainAlignment::isAligned($spfDomain, $headerFrom, $aspf)) {
            $notes[] = sprintf(
                'Worth knowing: the reporter counted SPF as passing even though the envelope domain named here (%s) does not obviously align with %s.',
                $spfDomain,
                $headerFrom,
            );
        }

        return $notes;
    }

    private static function requirement(DmarcAlignment $mode): string
    {
        return DmarcAlignment::Strict === $mode
            ? 'strict alignment, where the two domains have to match exactly'
            : 'relaxed alignment, where the two domains have to share one organisational domain';
    }
}
