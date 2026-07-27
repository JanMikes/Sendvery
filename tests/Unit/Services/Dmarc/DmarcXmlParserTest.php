<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dmarc;

use App\Exceptions\InvalidDmarcReportXml;
use App\Services\Dmarc\DmarcXmlParser;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use PHPUnit\Framework\TestCase;

final class DmarcXmlParserTest extends TestCase
{
    private DmarcXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DmarcXmlParser();
    }

    public function testParsesGoogleReport(): void
    {
        $xml = file_get_contents(__DIR__.'/../../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('google.com', $report->reporterOrg);
        self::assertSame('noreply-dmarc-support@google.com', $report->reporterEmail);
        self::assertSame('17238456789012345678', $report->reportId);
        self::assertSame('example.com', $report->policyDomain);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAspf);
        self::assertSame(DmarcPolicy::Reject, $report->policyP);
        self::assertSame(DmarcPolicy::Quarantine, $report->policySp);
        self::assertSame(100, $report->policyPct);
        self::assertCount(2, $report->records);

        $firstRecord = $report->records[0];
        self::assertSame('209.85.220.41', $firstRecord->sourceIp);
        self::assertSame(150, $firstRecord->count);
        self::assertSame(Disposition::None, $firstRecord->disposition);
        self::assertSame(AuthResult::Pass, $firstRecord->dkimResult);
        self::assertSame(AuthResult::Pass, $firstRecord->spfResult);
        self::assertSame('example.com', $firstRecord->headerFrom);
        self::assertSame('example.com', $firstRecord->dkimDomain);
        self::assertSame('google', $firstRecord->dkimSelector);
        self::assertSame('example.com', $firstRecord->spfDomain);

        $secondRecord = $report->records[1];
        self::assertSame('185.70.42.3', $secondRecord->sourceIp);
        self::assertSame(5, $secondRecord->count);
        self::assertSame(Disposition::Reject, $secondRecord->disposition);
        self::assertSame(AuthResult::Fail, $secondRecord->dkimResult);
        self::assertSame(AuthResult::Fail, $secondRecord->spfResult);
    }

    public function testParsesYahooReport(): void
    {
        $xml = file_get_contents(__DIR__.'/../../../Fixtures/yahoo-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('Yahoo! Inc.', $report->reporterOrg);
        self::assertSame('dmarchelp@yahoo.com', $report->reporterEmail);
        self::assertSame(DmarcAlignment::Strict, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Strict, $report->policyAspf);
        self::assertSame(DmarcPolicy::Quarantine, $report->policyP);
        self::assertNull($report->policySp);
        self::assertSame(50, $report->policyPct);
        self::assertCount(1, $report->records);
    }

    public function testParsesMinimalReport(): void
    {
        $xml = file_get_contents(__DIR__.'/../../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('microsoft.com', $report->reporterOrg);
        self::assertSame(DmarcPolicy::None, $report->policyP);
        // Defaults to relaxed when not specified
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAspf);
        self::assertSame(100, $report->policyPct);
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Failed to parse XML');

        $this->parser->parse('not xml at all');
    }

    public function testThrowsOnMissingReportMetadata(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <report_metadata>');

        $this->parser->parse('<?xml version="1.0"?><feedback><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingPolicyPublished(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <policy_published>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata></feedback>');
    }

    public function testThrowsOnMissingReportId(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <report_id>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingPolicyDomain(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <domain>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnInvalidPolicy(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Invalid or missing <p>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>invalid</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingDateRangeBegin(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <date_range.begin>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnInvalidTimestamp(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Invalid timestamp');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>0</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testRecordsWithoutAReasonElementCarryNoOverrideReasons(): void
    {
        $xml = file_get_contents(__DIR__.'/../../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        // The overwhelmingly common case: receivers rarely explain themselves,
        // and a report that says nothing must produce nothing rather than a
        // placeholder that later reads as evidence.
        self::assertSame([], $report->records[0]->policyOverrideReasons, 'A record with no <reason> element records no override reasons');
        self::assertSame([], $report->records[1]->policyOverrideReasons, 'A record with no <reason> element records no override reasons');
    }

    public function testKeepsTheReceiversExplanationForOverridingThePolicy(): void
    {
        $report = $this->parser->parse($this->reportWithPolicyEvaluatedExtras(
            '<reason><type>trusted_forwarder</type><comment>relay on allowlist</comment></reason>',
        ));

        $reasons = $report->records[0]->policyOverrideReasons;

        self::assertCount(1, $reasons);
        self::assertSame(PolicyOverrideReasonType::TrustedForwarder, $reasons[0]->type);
        self::assertSame('relay on allowlist', $reasons[0]->comment);
    }

    public function testKeepsEveryReasonWhenTheReceiverGivesMoreThanOne(): void
    {
        $report = $this->parser->parse($this->reportWithPolicyEvaluatedExtras(
            '<reason><type>forwarded</type></reason>'
            .'<reason><type>sampled_out</type><comment>pct=20</comment></reason>'
            .'<reason><type>mailing_list</type></reason>',
        ));

        $reasons = $report->records[0]->policyOverrideReasons;

        // RFC 7489 §6.7 allows the element to repeat, and a message really can
        // be both forwarded and sampled out. Reading only the first would
        // silently drop half the receiver's testimony.
        self::assertCount(3, $reasons, 'Every repeated <reason> element is kept, not just the first');
        self::assertSame(
            [PolicyOverrideReasonType::Forwarded, PolicyOverrideReasonType::SampledOut, PolicyOverrideReasonType::MailingList],
            array_map(static fn (PolicyOverrideReason $reason): PolicyOverrideReasonType => $reason->type, $reasons),
        );
        self::assertNull($reasons[0]->comment, 'A <reason> with no <comment> records no comment');
        self::assertSame('pct=20', $reasons[1]->comment);
    }

    public function testUnrecognisedReasonTypeDoesNotBreakIngestion(): void
    {
        $report = $this->parser->parse($this->reportWithPolicyEvaluatedExtras(
            '<reason><type>vendor_specific_thing</type><comment>who knows</comment></reason>',
        ));

        $reasons = $report->records[0]->policyOverrideReasons;

        // A token nobody registered must never cost us an otherwise-valid
        // report; RFC 7489 §6.7.3 designates `other` as exactly this bucket.
        self::assertCount(1, $reasons);
        self::assertSame(PolicyOverrideReasonType::Other, $reasons[0]->type);
        self::assertSame('who knows', $reasons[0]->comment, 'The comment survives even when the type token does not');
        self::assertSame('209.85.220.41', $report->records[0]->sourceIp, 'The rest of the record parses normally');
    }

    public function testHostileReasonCommentIsBoundedBeforeItIsStored(): void
    {
        $report = $this->parser->parse($this->reportWithPolicyEvaluatedExtras(
            '<reason><type>local_policy</type><comment>'.str_repeat('a', 10_000).'</comment></reason>',
        ));

        $comment = $report->records[0]->policyOverrideReasons[0]->comment;

        // The comment is free text from a third party, and the RFC puts no
        // bound on it — one report must not be able to bloat the database.
        self::assertNotNull($comment);
        self::assertSame(PolicyOverrideReason::MAX_COMMENT_LENGTH, mb_strlen($comment));
    }

    public function testRecognisesGmailsArcValidatedLocalPolicyOverride(): void
    {
        $xml = file_get_contents(__DIR__.'/../../../Fixtures/policy-override-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        // Gmail's real shape: DMARC failed on both methods, yet the message was
        // delivered because Gmail validated the forwarder's ARC chain. That is
        // receiver-attested proof of forwarding, not a spoofing signal.
        $arcOverride = $report->records[0];
        self::assertSame(AuthResult::Fail, $arcOverride->dkimResult);
        self::assertSame(AuthResult::Fail, $arcOverride->spfResult);
        self::assertSame(Disposition::None, $arcOverride->disposition);
        self::assertCount(1, $arcOverride->policyOverrideReasons);
        self::assertSame(PolicyOverrideReasonType::LocalPolicy, $arcOverride->policyOverrideReasons[0]->type);
        self::assertSame('arc=pass', $arcOverride->policyOverrideReasons[0]->comment);

        self::assertCount(2, $report->records[1]->policyOverrideReasons);
    }

    /**
     * Builds a single-record report whose `<policy_evaluated>` carries the given
     * extra markup, so each reason case reads as one line instead of a wall of
     * XML.
     */
    private function reportWithPolicyEvaluatedExtras(string $policyEvaluatedExtras): string
    {
        return '<?xml version="1.0"?><feedback>'
            .'<report_metadata><org_name>google.com</org_name><report_id>reason-test</report_id>'
            .'<date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata>'
            .'<policy_published><domain>example.com</domain><p>reject</p></policy_published>'
            .'<record><row><source_ip>209.85.220.41</source_ip><count>4</count>'
            .'<policy_evaluated><disposition>none</disposition><dkim>fail</dkim><spf>fail</spf>'
            .$policyEvaluatedExtras
            .'</policy_evaluated></row>'
            .'<identifiers><header_from>example.com</header_from></identifiers>'
            .'</record></feedback>';
    }
}
