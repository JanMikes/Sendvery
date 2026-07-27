<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\DomainHealthScorer;
use App\Value\Dns\DkimCheckResult;
use App\Value\Dns\DmarcCheckResult;
use App\Value\Dns\EmailAuthCheckResult;
use App\Value\Dns\MxCheckResult;
use App\Value\Dns\MxRecord;
use App\Value\Dns\SpfCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A blacklist check that never ran must contribute nothing to the grade.
 *
 * THE DEFECT: `$blScore = $blacklistScore ?? 100` handed every unchecked domain
 * a perfect score carrying 20% of its grade. Nothing in the product dispatched
 * `CheckBlacklist`, so in practice EVERY domain banked that 20% — an F-grade
 * domain with no SPF, no DKIM and no DMARC scored 35 and was shown a D. The
 * fabricated points were worth up to a full letter, on a grade published to an
 * unauthenticated share page, a PDF and the REST API.
 *
 * The display half of this was already fixed: the surfaces say "Not checked".
 * That made the contradiction worse rather than better — the bar admitted we
 * had never looked while the number beside it still counted a perfect result.
 *
 * The fix renormalises over the categories actually measured, so an unmeasured
 * blacklist neither helps nor hurts. A perfect domain still scores 100 either
 * way; every imperfect domain's grade drops to what it always was.
 */
final class UnmeasuredBlacklistDoesNotInflateGradeTest extends TestCase
{
    #[Test]
    public function aDomainWithNothingConfiguredIsNotRescuedByAnUncheckedBlacklist(): void
    {
        $score = (new DomainHealthScorer())->score($this->domainWithOnlyValidMx());

        self::assertSame(
            'F',
            $score->grade,
            'SPF, DKIM and DMARC are all absent. The only thing keeping this domain off an F was 20 points for a blacklist lookup that never happened.',
        );
    }

    #[Test]
    public function aPerfectDomainStillScoresOneHundredWithoutABlacklistCheck(): void
    {
        $score = (new DomainHealthScorer())->score($this->perfectDomain());

        self::assertSame(100, $score->score, 'Renormalising must not penalise a domain for a check we chose not to run.');
        self::assertSame('A', $score->grade);
    }

    #[Test]
    public function aMeasuredCleanBlacklistScoresTheSameAsNoMeasurement(): void
    {
        $scorer = new DomainHealthScorer();

        self::assertSame(
            $scorer->score($this->perfectDomain())->score,
            $scorer->score($this->perfectDomain(), 100)->score,
            'A domain that is genuinely not listed should land where an unmeasured one does. If measuring clean RAISED the score, the unmeasured case was silently penalised instead.',
        );
    }

    #[Test]
    public function aMeasuredListingActuallyCostsTheDomain(): void
    {
        $scorer = new DomainHealthScorer();

        self::assertLessThan(
            $scorer->score($this->perfectDomain())->score,
            $scorer->score($this->perfectDomain(), 0)->score,
            'Once a blacklist check has actually run and found a listing, it must move the grade. A feature customers pay for cannot be inert.',
        );
    }

    #[Test]
    public function theBlacklistCategoryReportsItsOwnUnmeasuredState(): void
    {
        $categories = (new DomainHealthScorer())->score($this->perfectDomain())->categories;

        $blacklist = null;
        foreach ($categories as $category) {
            if ('Blacklist' === $category->name) {
                $blacklist = $category;
            }
        }

        self::assertNotNull($blacklist, 'The category must still be listed — silently dropping it would hide a paid feature rather than describe its state.');
        self::assertNull(
            $blacklist->score,
            'An unmeasured category has no score. Reporting a number here is what let a fabricated 100 reach the grade in the first place.',
        );
    }

    private function perfectDomain(): EmailAuthCheckResult
    {
        return new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 include:_spf.google.com ~all', true, 2, 1, ['_spf.google.com'], [], []),
            [new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], [])],
            new DmarcCheckResult('v=DMARC1; p=reject; rua=mailto:d@ex.com', 'reject', null, ['d@ex.com'], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );
    }

    private function domainWithOnlyValidMx(): EmailAuthCheckResult
    {
        return new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult(null, false, 0, 0, [], [], []),
            [],
            new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );
    }
}
