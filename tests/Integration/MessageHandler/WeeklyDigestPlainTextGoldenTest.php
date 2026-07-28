<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Services\Ai\AiInsightsService;
use App\Services\Digest\WeeklyDigestRenderer;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\FixedWeeklyDigestAiInsightsService;
use App\Tests\TestSupport\FullWeeklyDigestFixture;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The whole text/plain digest, word for word, in a file a human can read.
 *
 * WHY ONLY THE PLAIN TEXT. A golden file for the HTML part would be 300 lines
 * of inline-styled table markup: every spacing tweak would fail it, the diff
 * would be unreadable, and CLAUDE.md forbids pinning styling in tests anyway.
 * The text alternative is the opposite — it is pure content, so its diff is
 * exactly the change in what the customer is told. Nudge a number, drop a
 * caveat, silently lose a section, and the review shows a sentence changing
 * rather than a byte count.
 *
 * It also covers the half of the email nobody looks at. The HTML gets eyeballed
 * because it is pretty; the text alternative is the one that quietly went
 * missing a link for months.
 *
 * STALENESS. There is no self-healing path: a missing golden fails, and
 * regenerating with UPDATE_DIGEST_GOLDEN=1 fails too, on purpose, so a run can
 * never both rewrite the expectation and report success. Whoever regenerates
 * has to look at the diff and run the suite again.
 */
final class WeeklyDigestPlainTextGoldenTest extends IntegrationTestCase
{
    private const string GOLDEN = __DIR__.'/golden/weekly_digest.txt';

    #[Test]
    public function theWholeTextDigestReadsExactlyAsRecorded(): void
    {
        $rendered = self::normalise($this->renderDigestText());

        if ('1' === getenv('UPDATE_DIGEST_GOLDEN')) {
            file_put_contents(self::GOLDEN, $rendered);
            self::fail(
                'Golden digest rewritten from the current render. Read the diff — this is what customers '
                .'will be told — then re-run without UPDATE_DIGEST_GOLDEN.',
            );
        }

        self::assertFileExists(
            self::GOLDEN,
            'The recorded digest is missing. Regenerate it with UPDATE_DIGEST_GOLDEN=1 and review the result '
            .'before trusting it.',
        );

        self::assertSame(
            (string) file_get_contents(self::GOLDEN),
            $rendered,
            'The weekly digest now says something different. If the change is intended, regenerate with '
            .'UPDATE_DIGEST_GOLDEN=1 — but read it first: this text is emailed to customers unprompted and '
            .'cannot be corrected afterwards.',
        );
    }

    private function renderDigestText(): string
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = (new FullWeeklyDigestFixture($em))->seed(
            'digest-golden-'.Uuid::uuid7()->toString().'@example.com',
            'digest-golden-'.Uuid::uuid7()->toString(),
        );

        // Replaced before the renderer is pulled out of the container, so the
        // narration is the fixed sentence rather than anything off the network.
        self::getContainer()->set(AiInsightsService::class, new FixedWeeklyDigestAiInsightsService());

        return $this->getService(WeeklyDigestRenderer::class)->render($persona->team)->text;
    }

    /**
     * Two things in the digest cannot be pinned by the fixture: the reporting
     * period, which is a window ending now, and the domain's generated UUID
     * inside the review link. Everything else is fixed, so everything else is
     * compared literally.
     */
    private static function normalise(string $text): string
    {
        $normalised = preg_replace(
            '/^[A-Z][a-z]{2} \d{1,2} — [A-Z][a-z]{2} \d{1,2}, \d{4}$/m',
            '{reporting period}',
            $text,
        );
        assert(is_string($normalised));

        $normalised = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{domainId}',
            $normalised,
        );
        assert(is_string($normalised));

        return $normalised;
    }
}
