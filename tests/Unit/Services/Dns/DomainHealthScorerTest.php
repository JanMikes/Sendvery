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

final class DomainHealthScorerTest extends TestCase
{
    private DomainHealthScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new DomainHealthScorer();
    }

    #[Test]
    public function wellConfiguredDomainGetsGradeA(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 include:_spf.google.com ~all', true, 2, 1, ['_spf.google.com'], [], []),
            [new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], [])],
            new DmarcCheckResult('v=DMARC1; p=reject; rua=mailto:d@ex.com', 'reject', null, ['d@ex.com'], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );

        $score = $this->scorer->score($result);

        self::assertSame('A', $score->grade);
        self::assertGreaterThanOrEqual(90, $score->score);
        self::assertCount(5, $score->categories);
    }

    #[Test]
    public function missingEverythingGetsGradeFWithDefaultBlacklist(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult(null, false, 0, 0, [], [], []),
            [new DkimCheckResult(null, false, null, null, 'default', [], [])],
            new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []),
            new MxCheckResult([], []),
        );

        $score = $this->scorer->score($result);

        // With blacklist defaulting to 100, 20% of 100 = 20 -> grade F
        self::assertSame('F', $score->grade);
        self::assertSame(20, $score->score);
    }

    #[Test]
    public function missingEverythingWithZeroBlacklistGetsF(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult(null, false, 0, 0, [], [], []),
            [new DkimCheckResult(null, false, null, null, 'default', [], [])],
            new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []),
            new MxCheckResult([], []),
        );

        $score = $this->scorer->score($result, blacklistScore: 0);

        self::assertSame('F', $score->grade);
        self::assertSame(0, $score->score);
    }

    #[Test]
    public function dmarcNoneWithValidSpfGetsCOrD(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 ~all', true, 1, 0, [], [], []),
            [new DkimCheckResult(null, false, null, null, 'default', [], [])],
            new DmarcCheckResult('v=DMARC1; p=none', 'none', null, [], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );

        $score = $this->scorer->score($result);

        self::assertContains($score->grade, ['C', 'D']);
    }

    #[Test]
    public function quarantinePolicyGetsB(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 include:_spf.google.com ~all', true, 2, 1, ['_spf.google.com'], [], []),
            [new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], [])],
            new DmarcCheckResult('v=DMARC1; p=quarantine; rua=mailto:d@ex.com', 'quarantine', null, ['d@ex.com'], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );

        $score = $this->scorer->score($result);

        self::assertContains($score->grade, ['A', 'B']);
        self::assertGreaterThanOrEqual(75, $score->score);
    }

    #[Test]
    public function categoriesHaveCorrectStructure(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 ~all', true, 1, 0, [], [], []),
            [new DkimCheckResult(null, false, null, null, 'default', [], [])],
            new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []),
            new MxCheckResult([], []),
        );

        $score = $this->scorer->score($result);

        $categoryNames = array_map(fn ($cat) => $cat->name, $score->categories);
        self::assertSame(['SPF', 'DKIM', 'DMARC', 'MX', 'Blacklist'], $categoryNames);

        foreach ($score->categories as $category) {
            self::assertContains($category->status, ['pass', 'warning', 'fail']);
            self::assertGreaterThanOrEqual(0, $category->score);
            self::assertLessThanOrEqual(100, $category->score);
        }
    }
}
