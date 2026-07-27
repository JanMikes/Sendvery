<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Reports;

use App\Value\DmarcAlignment;
use App\Value\Reports\DomainAlignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DMARC identifier alignment (RFC 7489 §3.1) is what turns "SPF passed" into
 * "SPF passed for the domain the reader actually sees". Getting relaxed vs
 * strict wrong would make the report detail page explain failures with the
 * wrong cause, so each rule is pinned here.
 */
final class DomainAlignmentTest extends TestCase
{
    #[Test]
    public function identicalDomainsAlignUnderStrict(): void
    {
        self::assertTrue(DomainAlignment::isAligned('acme.example', 'acme.example', DmarcAlignment::Strict));
    }

    #[Test]
    public function identicalDomainsAlignUnderRelaxed(): void
    {
        self::assertTrue(DomainAlignment::isAligned('acme.example', 'acme.example', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function comparisonIgnoresCaseAndTrailingDot(): void
    {
        self::assertTrue(DomainAlignment::isAligned('ACME.example.', 'acme.example', DmarcAlignment::Strict));
    }

    #[Test]
    public function subdomainAlignsUnderRelaxedButNotUnderStrict(): void
    {
        self::assertTrue(
            DomainAlignment::isAligned('mail.acme.example', 'acme.example', DmarcAlignment::Relaxed),
            'Relaxed alignment accepts a subdomain of the From domain.',
        );
        self::assertFalse(
            DomainAlignment::isAligned('mail.acme.example', 'acme.example', DmarcAlignment::Strict),
            'Strict alignment demands an exact match, so a subdomain must not align.',
        );
    }

    #[Test]
    public function parentDomainAlignsUnderRelaxed(): void
    {
        // A message From news.acme.example signed by acme.example still shares an
        // organisational domain, so relaxed alignment holds in both directions.
        self::assertTrue(DomainAlignment::isAligned('acme.example', 'news.acme.example', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function siblingSubdomainsAlignUnderRelaxed(): void
    {
        self::assertTrue(DomainAlignment::isAligned('mail.acme.example', 'news.acme.example', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function unrelatedDomainsNeverAlign(): void
    {
        self::assertFalse(DomainAlignment::isAligned('sendgrid.net', 'acme.example', DmarcAlignment::Relaxed));
        self::assertFalse(DomainAlignment::isAligned('sendgrid.net', 'acme.example', DmarcAlignment::Strict));
    }

    #[Test]
    public function domainsSharingOnlyAPublicSuffixDoNotAlign(): void
    {
        // The classic false-positive trap: co.uk is a public suffix, not an
        // organisational domain, so these two are strangers.
        self::assertFalse(DomainAlignment::isAligned('evil.co.uk', 'acme.co.uk', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function subdomainsUnderAMultiLabelPublicSuffixAlign(): void
    {
        self::assertTrue(DomainAlignment::isAligned('mail.acme.co.uk', 'news.acme.co.uk', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function emptyIdentifierNeverAligns(): void
    {
        // Reporters do send rows with an empty auth_results domain; treating that
        // as "aligned" would invent a pass out of nothing.
        self::assertFalse(DomainAlignment::isAligned('', 'acme.example', DmarcAlignment::Relaxed));
        self::assertFalse(DomainAlignment::isAligned('acme.example', '', DmarcAlignment::Relaxed));
    }

    #[Test]
    public function organisationalDomainOfATwoLabelDomainIsItself(): void
    {
        self::assertSame('acme.example', DomainAlignment::organisationalDomain('acme.example'));
    }

    #[Test]
    public function organisationalDomainStripsSubdomains(): void
    {
        self::assertSame('acme.example', DomainAlignment::organisationalDomain('bounces.mail.acme.example'));
    }

    #[Test]
    public function organisationalDomainKeepsThreeLabelsForMultiLabelPublicSuffixes(): void
    {
        self::assertSame('acme.co.uk', DomainAlignment::organisationalDomain('mail.acme.co.uk'));
        self::assertSame('acme.com.au', DomainAlignment::organisationalDomain('bounces.mail.acme.com.au'));
    }

    #[Test]
    public function organisationalDomainOfASingleLabelIsItself(): void
    {
        self::assertSame('localhost', DomainAlignment::organisationalDomain('localhost'));
    }
}
