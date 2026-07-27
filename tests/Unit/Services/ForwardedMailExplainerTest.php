<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\ForwardedMailExplanation;
use App\Results\ReportSenderGroupResult;
use App\Services\ForwardedMailExplainer;
use App\Value\ForwardedMailOutcome;
use App\Value\ForwardingAttestation;
use App\Value\PolicyOverrideReasonType;
use App\Value\SenderRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Built from the production data of 2026-07-27: a domain publishing
 * `p=quarantine` with six legitimate messages in spam folders, every one of
 * them relayed by a recipient-side gateway that rewrote the body.
 *
 * @see docs/18-forwarder-trust-verification-plan.md §3 (DEC-060 WP-E)
 */
final class ForwardedMailExplainerTest extends TestCase
{
    private ForwardedMailExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new ForwardedMailExplainer();
    }

    #[Test]
    public function saysNothingAboutASenderThatIsNotAForwarder(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Suspicious,
            totalMessages: 400,
            quarantine: 400,
        ));

        self::assertFalse(
            $explanation->isWorthSaying(),
            'Forwarding excuses a forwarder. Offering the same excuse to a sender nothing identified would launder exactly the mail this product exists to surface.',
        );
        self::assertSame(ForwardedMailOutcome::NothingToExplain, $explanation->outcome);
    }

    #[Test]
    public function saysNothingWhenAForwardersMailAllGotThrough(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            totalMessages: 12,
            dkimPassCount: 12,
            none: 12,
        ));

        self::assertFalse(
            $explanation->isWorthSaying(),
            'Narrating a success is how a product teaches people to skim past its own text.',
        );
    }

    #[Test]
    public function explainsTheGatewayThatRewroteEveryMessageItRelayed(): void
    {
        // inkyphishfence.com in production: two messages, both rewritten, both
        // quarantined.
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            label: 'inkyphishfence.com',
            totalMessages: 2,
            quarantine: 2,
        ));

        self::assertSame(ForwardedMailOutcome::Quarantined, $explanation->outcome);
        self::assertSame(2, $explanation->affectedMessages);
        self::assertStringContainsString('2 forwarded messages went to spam', $explanation->headline);
        self::assertStringContainsString(
            'not a fault at your end',
            $explanation->headline,
            'The count is what a skimming reader takes away, so the verdict has to travel with it.',
        );
        self::assertStringContainsString('inkyphishfence.com is a mail gateway on the recipient\'s side', $explanation->whyItHappened);
        self::assertStringContainsString('SPF cannot survive that hop by design', $explanation->whyItHappened);
        self::assertStringContainsString('did not survive either, because the gateway rewrote the message', $explanation->whyItHappened);
    }

    #[Test]
    public function saysWhichMessagesKeptTheirSignatureWhenOnlySomeWereRewritten(): void
    {
        // cloud-sec-av.com in production: one message relayed untouched and
        // delivered, three rewritten and quarantined — one gateway product, one
        // receiver, one day.
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            label: 'cloud-sec-av.com',
            totalMessages: 4,
            dkimPassCount: 1,
            none: 1,
            quarantine: 3,
        ));

        self::assertSame(3, $explanation->affectedMessages);
        self::assertStringContainsString(
            'did survive on 1 of these 4 messages, which is why those were delivered',
            $explanation->whyItHappened,
            'The DKIM half is the whole difference between a forward that arrives and one that does not, so it is read off the counts rather than assumed.',
        );
    }

    #[Test]
    public function reportsRefusedMailAsRefusedRatherThanAsSpam(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            totalMessages: 1,
            reject: 1,
        ));

        self::assertSame(ForwardedMailOutcome::Rejected, $explanation->outcome);
        self::assertStringContainsString(
            '1 forwarded message was refused',
            $explanation->headline,
            'A recipient can retrieve mail from spam and cannot retrieve mail that bounced; the two are not the same news.',
        );
    }

    #[Test]
    public function leadsWithTheHarsherOutcomeWhenASenderMetBoth(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            totalMessages: 9,
            quarantine: 5,
            reject: 4,
        ));

        self::assertSame(ForwardedMailOutcome::Rejected, $explanation->outcome);
        self::assertSame(9, $explanation->affectedMessages, 'Every message the receiver held back counts, however it held it.');
    }

    #[Test]
    public function tellsTheReaderThereIsNothingToFixInsteadOfInventingAnAction(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            totalMessages: 3,
            quarantine: 3,
        ));

        self::assertStringStartsWith('Nothing,', $explanation->whatYouCanDo);
        self::assertStringContainsString('known cost of forwarding', $explanation->whatYouCanDo);
        self::assertStringContainsString(
            'ARC seal',
            $explanation->whatYouCanDo,
            'The second real option is that receivers honouring ARC already deliver this mail — which is why the same gateway succeeds elsewhere.',
        );
        self::assertStringContainsString(
            'not a reason to weaken your DMARC policy',
            $explanation->whatYouCanDo,
            'A reader shown undelivered mail with no available action will reach for the one lever they do control and undo real protection.',
        );
    }

    #[Test]
    public function neverSuggestsTheReaderFixSomethingTheyDoNotOperate(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: SenderRole::Forwarder,
            totalMessages: 3,
            quarantine: 3,
        ));

        $everythingSaid = $explanation->headline.' '.$explanation->whyItHappened.' '.$explanation->whatYouCanDo;

        foreach (['misconfigur', 'fix your', 'add it to your SPF', 'investigate'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $everythingSaid,
                'Classifying the sender correctly and then printing remediation advice underneath would waste the entire effort.',
            );
        }
    }

    #[Test]
    public function believesAReceiverThatAttestedTheForwardEvenWithNoCachedRole(): void
    {
        // The identity cache is populated lazily and holds only globally true
        // facts, so a host can be an attested forwarder with no role stored.
        $explanation = $this->explainer->explain($this->group(
            role: null,
            totalMessages: 6,
            quarantine: 6,
            forwarding: new ForwardingAttestation(true, PolicyOverrideReasonType::MailingList),
        ));

        self::assertSame(ForwardedMailOutcome::Quarantined, $explanation->outcome);
    }

    #[Test]
    public function anUnclassifiedSenderIsNotHandedAForwardersExcuse(): void
    {
        $explanation = $this->explainer->explain($this->group(
            role: null,
            totalMessages: 6,
            quarantine: 6,
        ));

        self::assertFalse(
            $explanation->isWorthSaying(),
            'Not knowing what a sender is must never read the same as knowing it is harmless.',
        );
    }

    #[Test]
    public function anEmptyExplanationCarriesNoCopyToRender(): void
    {
        $nothing = ForwardedMailExplanation::nothingToExplain();

        self::assertSame('', $nothing->headline);
        self::assertSame('', $nothing->whyItHappened);
        self::assertSame('', $nothing->whatYouCanDo);
        self::assertSame(0, $nothing->affectedMessages);
    }

    private function group(
        ?SenderRole $role,
        string $label = 'cloud-sec-av.com',
        int $totalMessages = 0,
        int $dkimPassCount = 0,
        int $none = 0,
        int $quarantine = 0,
        int $reject = 0,
        ?ForwardingAttestation $forwarding = null,
    ): ReportSenderGroupResult {
        return new ReportSenderGroupResult(
            groupKey: $label,
            displayLabel: $label,
            totalMessages: $totalMessages,
            dkimPassCount: $dkimPassCount,
            dkimPassRate: $totalMessages > 0 ? $dkimPassCount / $totalMessages * 100 : 0.0,
            spfPassCount: 0,
            spfPassRate: 0.0,
            dispositionNone: $none,
            dispositionQuarantine: $quarantine,
            dispositionReject: $reject,
            sourceIps: ['203.0.113.9'],
            senderIsAuthorized: false,
            senderRole: $role,
            forwarding: $forwarding ?? ForwardingAttestation::none(),
        );
    }
}
