<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\Reports\AuthMechanismOutcome;
use App\Value\Reports\RecordAlignmentVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The report detail page has to answer "why did this mail fail?" without an AI
 * plan. That answer is built here: three failure causes with three different
 * fixes (nothing signed / signed by somebody else / signature broke), told apart
 * from the identifiers the aggregate report carries.
 */
final class RecordAlignmentVerdictTest extends TestCase
{
    private function evaluate(
        string $dkimResult = 'fail',
        ?string $dkimDomain = null,
        ?string $dkimSelector = null,
        string $spfResult = 'fail',
        ?string $spfDomain = null,
        string $headerFrom = 'acme.example',
        string $policyAdkim = 'r',
        string $policyAspf = 'r',
    ): RecordAlignmentVerdict {
        return RecordAlignmentVerdict::evaluate(
            headerFrom: $headerFrom,
            dkimResult: $dkimResult,
            dkimDomain: $dkimDomain,
            dkimSelector: $dkimSelector,
            spfResult: $spfResult,
            spfDomain: $spfDomain,
            policyAdkim: $policyAdkim,
            policyAspf: $policyAspf,
        );
    }

    private function joinedExplanations(RecordAlignmentVerdict $verdict): string
    {
        return implode("\n", $verdict->explanations);
    }

    #[Test]
    public function dkimPassAloneMakesTheMessagePassDmarc(): void
    {
        $verdict = $this->evaluate(dkimResult: 'pass', dkimDomain: 'acme.example', dkimSelector: 'sel1');

        self::assertTrue($verdict->dmarcPass, 'DMARC needs only one of the two mechanisms to authenticate and align.');
        self::assertSame('DMARC pass', $verdict->headline);
        self::assertSame('success', $verdict->tone);
        self::assertSame(AuthMechanismOutcome::Aligned, $verdict->dkim);
        self::assertStringContainsString('d=acme.example', $this->joinedExplanations($verdict));
        self::assertStringContainsString('selector s=sel1', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function spfPassAloneMakesTheMessagePassDmarc(): void
    {
        $verdict = $this->evaluate(spfResult: 'pass', spfDomain: 'acme.example');

        self::assertTrue($verdict->dmarcPass);
        self::assertSame(AuthMechanismOutcome::Aligned, $verdict->spf);
        self::assertStringContainsString('SPF passed', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function aPassingMechanismWithNoIdentifierNamedStillReadsAsAligned(): void
    {
        // Plenty of reporters omit <auth_results> detail entirely; the evaluated
        // result is still authoritative and must not be re-litigated.
        $verdict = $this->evaluate(dkimResult: 'pass', spfResult: 'pass');

        self::assertTrue($verdict->dmarcPass);
        self::assertStringContainsString('did not name the signing domain', $this->joinedExplanations($verdict));
        self::assertStringContainsString('SPF passed and aligned', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function everythingFailingIsReportedAsADmarcFailure(): void
    {
        $verdict = $this->evaluate();

        self::assertFalse($verdict->dmarcPass);
        self::assertSame('DMARC fail', $verdict->headline);
        self::assertSame('error', $verdict->tone);
        self::assertStringContainsString('DMARC fails', $verdict->explanations[0]);
    }

    #[Test]
    public function unsignedMailIsExplainedAsMissingDkimNotAsMisalignment(): void
    {
        $verdict = $this->evaluate(dkimDomain: null);

        self::assertSame(AuthMechanismOutcome::NotPresent, $verdict->dkim);
        self::assertStringContainsString('No DKIM signature was reported', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function anEmptyDkimDomainCountsAsNoSignature(): void
    {
        $verdict = $this->evaluate(dkimDomain: '');

        self::assertSame(AuthMechanismOutcome::NotPresent, $verdict->dkim);
    }

    #[Test]
    public function mailSignedBySomebodyElseIsExplainedAsMisaligned(): void
    {
        $verdict = $this->evaluate(dkimDomain: 'sendgrid.net', dkimSelector: 's1');

        self::assertSame(AuthMechanismOutcome::Misaligned, $verdict->dkim);
        $text = $this->joinedExplanations($verdict);
        self::assertStringContainsString('DKIM did not align', $text);
        self::assertStringContainsString('d=sendgrid.net', $text);
        self::assertStringContainsString('acme.example', $text);
        self::assertStringContainsString('relaxed alignment', $text);
        self::assertStringContainsString('CNAME', $text, 'The fix — publish the provider key under your own domain — must be spelled out.');
    }

    #[Test]
    public function anAlignedSignatureThatDidNotVerifyPointsAtDnsOrModification(): void
    {
        $verdict = $this->evaluate(dkimDomain: 'acme.example', dkimSelector: 'sel1');

        self::assertSame(AuthMechanismOutcome::AuthenticationFailed, $verdict->dkim);
        $text = $this->joinedExplanations($verdict);
        self::assertStringContainsString('did not verify', $text);
        self::assertStringContainsString('rotated out of DNS', $text);
    }

    #[Test]
    public function aForwardedOrEspEnvelopeIsExplainedAsSpfMisalignment(): void
    {
        $verdict = $this->evaluate(spfDomain: 'bounces.mailer.example');

        self::assertSame(AuthMechanismOutcome::Misaligned, $verdict->spf);
        $text = $this->joinedExplanations($verdict);
        self::assertStringContainsString('SPF did not align', $text);
        self::assertStringContainsString('bounces.mailer.example', $text);
        self::assertStringContainsString('forwarded mail', $text);
    }

    #[Test]
    public function anAlignedEnvelopeThatFailedSpfPointsAtTheSpfRecord(): void
    {
        $verdict = $this->evaluate(spfDomain: 'acme.example');

        self::assertSame(AuthMechanismOutcome::AuthenticationFailed, $verdict->spf);
        self::assertStringContainsString('not listed in its SPF record', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function aMissingSpfIdentityIsSaidOutLoud(): void
    {
        $verdict = $this->evaluate(spfDomain: null);

        self::assertSame(AuthMechanismOutcome::NotPresent, $verdict->spf);
        self::assertStringContainsString('No SPF result was reported', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function anEmptySpfDomainCountsAsNoSpfIdentity(): void
    {
        $verdict = $this->evaluate(spfDomain: '');

        self::assertSame(AuthMechanismOutcome::NotPresent, $verdict->spf);
    }

    #[Test]
    public function strictPolicyIsExplainedAsRequiringAnExactMatch(): void
    {
        $verdict = $this->evaluate(
            dkimDomain: 'mail.acme.example',
            spfDomain: 'mail.acme.example',
            policyAdkim: 's',
            policyAspf: 's',
        );

        self::assertSame(AuthMechanismOutcome::Misaligned, $verdict->dkim, 'Under strict alignment a subdomain does not align.');
        self::assertSame(AuthMechanismOutcome::Misaligned, $verdict->spf);
        self::assertStringContainsString('strict alignment', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function subdomainSignaturesAlignUnderTheDefaultRelaxedPolicy(): void
    {
        $verdict = $this->evaluate(dkimDomain: 'mail.acme.example');

        self::assertSame(
            AuthMechanismOutcome::AuthenticationFailed,
            $verdict->dkim,
            'Under relaxed alignment mail.acme.example aligns, so the failure is the signature itself.',
        );
    }

    #[Test]
    public function anUnrecognisedAlignmentModeFallsBackToRelaxed(): void
    {
        // Defensive: a reporter (or a future schema change) handing us something
        // other than r/s must not make the page explain failures as strict.
        $verdict = $this->evaluate(
            dkimDomain: 'mail.acme.example',
            spfDomain: 'mail.acme.example',
            policyAdkim: 'nonsense',
            policyAspf: 'nonsense',
        );

        // A subdomain identifier aligns only under relaxed, so these outcomes are
        // proof the fallback picked relaxed rather than strict.
        self::assertSame(AuthMechanismOutcome::AuthenticationFailed, $verdict->dkim);
        self::assertSame(AuthMechanismOutcome::AuthenticationFailed, $verdict->spf);
    }

    #[Test]
    public function aPassingDkimWhoseNamedDomainLooksUnalignedSaysSoInsteadOfContradictingTheBadge(): void
    {
        // A message can carry several DKIM signatures while the aggregate report
        // names only one. The reporter's pass wins, but we admit the mismatch.
        $verdict = $this->evaluate(dkimResult: 'pass', dkimDomain: 'sendgrid.net');

        self::assertTrue($verdict->dmarcPass);
        $text = $this->joinedExplanations($verdict);
        self::assertStringContainsString('Worth knowing', $text);
        self::assertStringContainsString('more than one DKIM signature', $text);
    }

    #[Test]
    public function aPassingSpfWhoseNamedEnvelopeLooksUnalignedSaysSoToo(): void
    {
        $verdict = $this->evaluate(spfResult: 'pass', spfDomain: 'bounces.mailer.example');

        self::assertTrue($verdict->dmarcPass);
        self::assertStringContainsString('the envelope domain named here', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function aCleanPassAddsNoCaveats(): void
    {
        $verdict = $this->evaluate(
            dkimResult: 'pass',
            dkimDomain: 'acme.example',
            spfResult: 'pass',
            spfDomain: 'acme.example',
        );

        self::assertCount(3, $verdict->explanations, 'A clean pass needs the verdict plus one sentence per mechanism — nothing more.');
        self::assertStringNotContainsString('Worth knowing', $this->joinedExplanations($verdict));
    }

    #[Test]
    public function aSignatureWithoutASelectorIsDescribedWithoutOne(): void
    {
        $verdict = $this->evaluate(dkimDomain: 'sendgrid.net', dkimSelector: '');

        self::assertStringNotContainsString('selector', $this->joinedExplanations($verdict));
    }
}
