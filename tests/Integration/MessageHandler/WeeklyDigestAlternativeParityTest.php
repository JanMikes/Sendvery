<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Message\SendWeeklyDigest;
use App\MessageHandler\SendWeeklyDigestHandler;
use App\Services\Ai\AiInsightsService;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Tests\Fixtures\Persona;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\FixedWeeklyDigestAiInsightsService;
use App\Tests\TestSupport\FullWeeklyDigestFixture;
use App\Value\WeeklyDigestSection;
use App\Value\WeeklyDigestSections;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The digest goes out as two alternatives — an HTML part and a text/plain part
 * — and the mail client picks whichever it prefers. Whatever the HTML says, the
 * text has to say too.
 *
 * This is the test the drift needed. "Waiting for your review" shipped
 * HTML-only and was hand-added to the text afterwards; its "Review these
 * senders" link was never added at all, so a text-only reader was told a
 * decision was outstanding and given no way to make it. Nothing failed, because
 * nothing compared the two.
 *
 * The comparison is driven by {@see WeeklyDigestSection}, so a section added to
 * one renderer and forgotten in the other cannot pass: a new case with no entry
 * in MARKERS fails immediately, and an entry whose marker never appears fails
 * on the render.
 */
final class WeeklyDigestAlternativeParityTest extends IntegrationTestCase
{
    /**
     * What each section looks like in each alternative. Deliberately not the
     * same string on both sides — the renderers are allowed to differ in shape,
     * only not in substance.
     *
     * @var array<string, array{html: string, text: string}>
     */
    private const array MARKERS = [
        'summary' => ['html' => 'Pass rate', 'text' => 'Overall pass rate:'],
        'ai_summary' => [
            'html' => FixedWeeklyDigestAiInsightsService::SUMMARY,
            'text' => FixedWeeklyDigestAiInsightsService::SUMMARY,
        ],
        'domain_breakdown' => ['html' => 'Domain breakdown', 'text' => 'Pass rate:'],
        'attention_alerts' => ['html' => 'Needs your attention', 'text' => 'Needs your attention:'],
        'resolved_alerts' => ['html' => 'resolved this week', 'text' => 'Resolved this week:'],
        'sender_review' => ['html' => 'Waiting for your review', 'text' => 'Waiting for your review'],
        'new_senders' => ['html' => 'New senders discovered', 'text' => 'New senders ('],
        'broken_dns' => ['html' => 'DNS records still broken', 'text' => 'DNS Records Still Broken:'],
        'dns_changes' => ['html' => 'DNS change', 'text' => 'DNS changes:'],
    ];

    private ?Persona $persona = null;

    #[Test]
    public function everySectionOfTheDigestIsCarriedByBothTheHtmlAndThePlainTextAlternative(): void
    {
        $email = $this->sendFullDigest();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        $sections = $this->sectionsOfTheDigest();

        foreach (WeeklyDigestSection::cases() as $section) {
            self::assertArrayHasKey(
                $section->value,
                self::MARKERS,
                sprintf(
                    'The digest grew a "%s" section. Say how it appears in the HTML and in the plain text, '
                    .'so both alternatives are held to it.',
                    $section->value,
                ),
            );

            self::assertTrue(
                $sections->has($section),
                sprintf(
                    'The fixture no longer produces a "%s" section, so nothing here proves the two alternatives '
                    .'agree about it. Extend FullWeeklyDigestFixture until it does.',
                    $section->value,
                ),
            );

            self::assertStringContainsString(
                self::MARKERS[$section->value]['html'],
                $html,
                sprintf('The HTML alternative is missing the "%s" section.', $section->value),
            );
            self::assertStringContainsString(
                self::MARKERS[$section->value]['text'],
                $text,
                sprintf('The plain-text alternative is missing the "%s" section.', $section->value),
            );
        }
    }

    #[Test]
    public function aReaderOnATextOnlyClientGetsTheSameLinkToReviewSendersAsAnHtmlReader(): void
    {
        // The exact drift this suite exists for. Telling somebody a decision is
        // outstanding and then withholding the page where it is made is worse
        // than not mentioning it: it leaves the reader with a chore and no door.
        $email = $this->sendFullDigest();
        $reviewUrl = $this->senderReviewUrl();

        // Entity-decoded: Twig escapes `&` inside an href, so a second query
        // parameter would make a raw comparison fail for a reason that has
        // nothing to do with parity.
        self::assertStringContainsString(
            $reviewUrl,
            html_entity_decode((string) $email->getHtmlBody()),
            'The HTML digest has always deep-linked the filtered sender list.',
        );
        self::assertStringContainsString(
            $reviewUrl,
            (string) $email->getTextBody(),
            'A text-only reader must get the same deep link, not just the count.',
        );
    }

    #[Test]
    public function bothAlternativesReportTheSameNumberOfThingsNeedingAttention(): void
    {
        // Presence parity is not enough: two sections can both be "present" and
        // still put different numbers in front of the same customer. The HTML
        // counts alert GROUPS — its own comment explains that a raw count above
        // a short list "looked like the email had swallowed something" — while
        // the plain text was still printing the raw count. A week of four
        // alerts in two groups read as "(2)" in one part and "4" in the other.
        $email = $this->sendFullDigest();

        preg_match(
            '/Needs your attention \((\d+)\)/',
            strip_tags((string) $email->getHtmlBody()),
            $htmlCount,
        );
        preg_match('/Needs attention: (\d+)/', (string) $email->getTextBody(), $textCount);

        self::assertArrayHasKey(1, $htmlCount, 'The HTML must state how many things need attention.');
        self::assertArrayHasKey(1, $textCount, 'The plain text must state how many things need attention.');

        self::assertSame(
            $htmlCount[1],
            $textCount[1],
            'The two alternatives of one email must not disagree about how much is wrong.',
        );

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($this->fixture()->team);
        self::assertGreaterThan(
            $digest->attentionAlertGroups,
            $digest->alertsCount,
            'This fixture must collapse several alerts into fewer groups, or the two numbers are '
            .'indistinguishable and nothing here is being tested.',
        );
        self::assertSame(
            (string) $digest->attentionAlertGroups,
            $textCount[1],
            'Both parts show grouped rows, so both must count groups — not the raw alerts behind them.',
        );
    }

    #[Test]
    public function neitherAlternativeInventsAZeroPercentPassRateForADomainThatReportedNothing(): void
    {
        // This surface arrives unprompted and cannot be corrected once sent, so
        // "unknown is not failure" is checked on both parts rather than on
        // whichever one a dashboard test happened to look at.
        $persona = $this->fixture();
        $em = $this->getService(EntityManagerInterface::class);

        $quiet = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'quiet.example',
            createdAt: new \DateTimeImmutable('-1 day'),
        );
        $quiet->popEvents();
        $em->persist($quiet);
        $em->flush();

        $email = $this->sendFullDigest();

        // Both alternatives must name what the reader is waiting for. A measured
        // 0.0% elsewhere in the same email is fine and stays — the rule is that
        // "not measured" and "measured zero" must not print the same thing.
        self::assertStringContainsString('Waiting for first report', (string) $email->getHtmlBody());
        self::assertStringContainsString('waiting for first report', (string) $email->getTextBody());
    }

    private function sendFullDigest(): Email
    {
        $persona = $this->fixture();

        self::getContainer()->set(AiInsightsService::class, new FixedWeeklyDigestAiInsightsService());
        $this->getService(SendWeeklyDigestHandler::class)(new SendWeeklyDigest($persona->team->id));

        $messages = self::getMailerMessages();
        self::assertNotSame([], $messages, 'Expected the digest to be sent to the team owner.');
        $message = $messages[count($messages) - 1];
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    private function sectionsOfTheDigest(): WeeklyDigestSections
    {
        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($this->fixture()->team);

        return WeeklyDigestSections::of($digest, hasAiSummary: true);
    }

    private function senderReviewUrl(): string
    {
        $domain = $this->fixture()->domain;
        assert(null !== $domain);

        return $this->getService(UrlGeneratorInterface::class)->generate(
            'dashboard_sender_inventory',
            ['domainId' => $domain->id->toString(), 'filter' => 'needs_review'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function fixture(): Persona
    {
        return $this->persona ??= (new FullWeeklyDigestFixture($this->getService(EntityManagerInterface::class)))
            ->seed(
                'digest-parity-'.Uuid::uuid7()->toString().'@example.com',
                'digest-parity-'.Uuid::uuid7()->toString(),
            );
    }
}
