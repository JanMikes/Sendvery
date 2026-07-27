<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\ForwardingAttestation;
use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DEC-060 WP-A — tier B of the evidence ladder.
 */
final class ForwardingAttestationTest extends TestCase
{
    #[Test]
    public function attestsNothingWhenNoReceiverSaidAnything(): void
    {
        $attestation = ForwardingAttestation::fromReasons([]);

        self::assertFalse($attestation->attestsForwarding);
        self::assertNull($attestation->attestedBy);
    }

    #[Test]
    public function theDefaultInstanceGrantsNothing(): void
    {
        self::assertFalse(
            new ForwardingAttestation()->attestsForwarding,
            'Silence must never read as a receiver vouching for a sender.',
        );
        self::assertFalse(ForwardingAttestation::none()->attestsForwarding);
    }

    /**
     * @return iterable<string, array{PolicyOverrideReasonType}>
     */
    public static function forwardingReasonTypes(): iterable
    {
        yield 'the receiver knows the message was relayed' => [PolicyOverrideReasonType::Forwarded];
        yield 'the relay is on the receiver trusted-forwarder list' => [PolicyOverrideReasonType::TrustedForwarder];
        yield 'a mailing list exploded the message to subscribers' => [PolicyOverrideReasonType::MailingList];
    }

    #[Test]
    #[DataProvider('forwardingReasonTypes')]
    public function treatsEveryDirectStatementOfForwardingAsAnAttestation(PolicyOverrideReasonType $type): void
    {
        $attestation = ForwardingAttestation::fromReasons([new PolicyOverrideReason($type)]);

        self::assertTrue($attestation->attestsForwarding);
        self::assertSame($type, $attestation->attestedBy, 'The copy explaining the verdict needs to name the evidence behind it.');
    }

    #[Test]
    public function readsAnArcValidatedLocalPolicyOverrideAsAForward(): void
    {
        $attestation = ForwardingAttestation::fromReasons([
            new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, 'arc=pass'),
        ]);

        self::assertTrue(
            $attestation->attestsForwarding,
            'A verified ARC seal means an intermediary handled the message and the receiver trusted it.',
        );
        self::assertSame(PolicyOverrideReasonType::LocalPolicy, $attestation->attestedBy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commentsThatDoNotSayArcPassed(): iterable
    {
        yield 'a local policy with no comment at all' => [''];
        yield 'a receiver exempting an internal sender' => ['internal sender exemption'];
        yield 'an ARC chain that did not validate' => ['arc=fail'];
        yield 'the token as a prefix of a longer word' => ['arc=passfail'];
        yield 'the token as a suffix of a longer word' => ['noarc=pass'];
        yield 'a sentence merely mentioning ARC' => ['we honour arc when the sealer is trusted'];
    }

    #[Test]
    #[DataProvider('commentsThatDoNotSayArcPassed')]
    public function refusesToReadForwardingIntoAnyOtherLocalPolicyComment(string $comment): void
    {
        $attestation = ForwardingAttestation::fromReasons([
            new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, $comment),
        ]);

        self::assertFalse(
            $attestation->attestsForwarding,
            'The comment is free text that ends up granting alert-suppressing trust, so only the exact ARC verdict counts.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commentsCarryingTheArcVerdict(): iterable
    {
        yield 'exactly as Gmail writes it' => ['arc=pass'];
        yield 'upper case' => ['ARC=PASS'];
        yield 'surrounded by other verdicts' => ['dmarc=fail arc=pass dkim=fail'];
        yield 'terminated by punctuation' => ['override applied: arc=pass.'];
        yield 'inside parentheses' => ['(arc=pass)'];
        yield 'separated by a semicolon' => ['spf=fail;arc=pass'];
    }

    #[Test]
    #[DataProvider('commentsCarryingTheArcVerdict')]
    public function findsTheArcVerdictWhereverTheReceiverPutItInTheComment(string $comment): void
    {
        self::assertTrue(
            ForwardingAttestation::fromReasons([
                new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, $comment),
            ])->attestsForwarding,
        );
    }

    #[Test]
    public function samplingAndTheCatchAllBucketAttestNothing(): void
    {
        $attestation = ForwardingAttestation::fromReasons([
            new PolicyOverrideReason(PolicyOverrideReasonType::SampledOut, 'pct=50'),
            new PolicyOverrideReason(PolicyOverrideReasonType::Other, 'forwarded by our gateway'),
        ]);

        self::assertFalse(
            $attestation->attestsForwarding,
            'Sampling is about pct=, not routing; and "other" is where unrecognised vendor tokens land, so reading forwarding into its text would grant trust on arbitrary strings.',
        );
    }

    #[Test]
    public function oneAttestingReasonIsEnoughEvenAlongsideReasonsThatAttestNothing(): void
    {
        $attestation = ForwardingAttestation::fromReasons([
            new PolicyOverrideReason(PolicyOverrideReasonType::SampledOut, 'pct=50'),
            new PolicyOverrideReason(PolicyOverrideReasonType::Forwarded),
        ]);

        self::assertTrue($attestation->attestsForwarding);
        self::assertSame(PolicyOverrideReasonType::Forwarded, $attestation->attestedBy);
    }

    #[Test]
    public function readsTheAttestationOutOfWhatTheIngestQueryAggregates(): void
    {
        // One JSON array per record the host appeared in, as json_agg produces.
        $aggregated = '[[{"type":"sampled_out","comment":"pct=50"}],[{"type":"forwarded","comment":null}]]';

        self::assertTrue(ForwardingAttestation::fromAggregatedJson($aggregated)->attestsForwarding);
    }

    #[Test]
    public function aReportWhereNoReceiverAnnotatedAnythingAttestsNothing(): void
    {
        // The FILTER in the ingest query selects NULL for this case, which is
        // every group in production today.
        self::assertFalse(ForwardingAttestation::fromAggregatedJson(null)->attestsForwarding);
        self::assertFalse(ForwardingAttestation::fromAggregatedJson('[[]]')->attestsForwarding);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePayloads(): iterable
    {
        yield 'not JSON at all' => ['not json'];
        yield 'a JSON scalar where an array belongs' => ['42'];
        yield 'a record holding a scalar instead of reasons' => ['["forwarded"]'];
        yield 'a reason with no type' => ['[[{"comment":"arc=pass"}]]'];
        yield 'a reason whose type is not a string' => ['[[{"type":7}]]'];
    }

    #[Test]
    #[DataProvider('unreadablePayloads')]
    public function degradesToNoAttestationRatherThanToTrustWhenThePayloadCannotBeRead(string $payload): void
    {
        self::assertFalse(
            ForwardingAttestation::fromAggregatedJson($payload)->attestsForwarding,
            'Forgiving decoding is only safe in the direction that grants nothing.',
        );
    }

    #[Test]
    public function keepsAnUnrecognisedVendorTokenOutOfTheAttestation(): void
    {
        // The parser folds unregistered tokens into `other`, which attests
        // nothing — so a reporter inventing a type cannot buy the role.
        self::assertFalse(
            ForwardingAttestation::fromAggregatedJson('[[{"type":"definitely_a_forward","comment":"trust me"}]]')->attestsForwarding,
        );
    }

    #[Test]
    public function ignoresACommentThatIsNotText(): void
    {
        self::assertFalse(
            ForwardingAttestation::fromAggregatedJson('[[{"type":"local_policy","comment":["arc=pass"]}]]')->attestsForwarding,
            'A local_policy override only attests forwarding through its comment, so a comment that is not text says nothing.',
        );
    }
}
