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

    /**
     * DEC-060 WP-E. Six legitimate messages sat in spam folders in production
     * because recipient-side gateways rewrote them. Nothing about the domain's
     * setup caused that, so nothing about it may cost the domain its grade —
     * a grade that dropped when somebody else's gateway rewrote a body would be
     * unfixable by definition, and an unfixable grade is a grade nobody trusts.
     *
     * The scorer therefore takes only what DNS proves plus the blacklist score,
     * and this test is the tripwire for a change that starts feeding message
     * outcomes in: the signature simply has nowhere to put them.
     */
    #[Test]
    public function gradesTheDomainsSetupAndNeverTheFateOfMailSomebodyElseForwarded(): void
    {
        $wellConfigured = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 include:_spf.google.com ~all', true, 2, 1, ['_spf.google.com'], [], []),
            [new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], [])],
            new DmarcCheckResult('v=DMARC1; p=quarantine; rua=mailto:d@ex.com', 'quarantine', null, ['d@ex.com'], [], null, null, null, [], []),
            new MxCheckResult([new MxRecord('mail.example.com', 10, '1.2.3.4', true, true)], []),
        );

        $parameters = new \ReflectionMethod($this->scorer, 'score')->getParameters();

        self::assertSame(
            ['result', 'blacklistScore'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters),
            'The grade is computed from DNS configuration and blacklist listings only. Quarantined forwards have no way in, and must not gain one.',
        );
        // The production domain publishes exactly this policy, and that policy
        // is what quarantined the six forwarded messages. Enforcing must raise
        // the grade, never lower it — otherwise the product would be charging
        // domains for doing the thing it spends the rest of its surface asking
        // them to do.
        self::assertGreaterThan(
            $this->scorer->score($this->sameSetupWithPolicy($wellConfigured, 'none'))->score,
            $this->scorer->score($wellConfigured)->score,
        );
    }

    private function sameSetupWithPolicy(EmailAuthCheckResult $result, string $policy): EmailAuthCheckResult
    {
        return new EmailAuthCheckResult(
            $result->domain,
            $result->spf,
            $result->dkim,
            new DmarcCheckResult('v=DMARC1; p='.$policy.'; rua=mailto:d@ex.com', $policy, null, ['d@ex.com'], [], null, null, null, [], []),
            $result->mx,
        );
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
