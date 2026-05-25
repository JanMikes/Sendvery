<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-127 — integration test for the inline token-level diff rendered on
 * `/app/domains/{id}/dns-history` CHANGED rows.
 *
 * Asserts the same surface ships both:
 *   1. the inline diff (struck-through old token + bolded new token within
 *      the same record line), and
 *   2. the `<details>` expander revealing the full before/after records,
 *      default-collapsed.
 *
 * Pairs with the unit suite for {@see \App\Services\Dns\DnsRecordDiffer} —
 * this test verifies the wiring (controller passes diffs to template, macro
 * renders the right CSS), not the diff algorithm itself.
 */
final class DnsHistoryInlineDiffTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domain: MonitoredDomain}
     */
    private function bootClientWithDomain(string $prefix): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($prefix.'-'.substr(uniqid('', true), -6))
            ->teamName('DNS History '.$prefix)
            ->build();

        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        return ['client' => $client, 'domain' => $persona->domain];
    }

    private function seedCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        \DateTimeImmutable $checkedAt,
        string $rawRecord,
        bool $isValid,
        bool $hasChanged,
        ?string $previousRawRecord = null,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: $checkedAt,
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: $previousRawRecord,
            hasChanged: $hasChanged,
            isFirstCheck: null === $previousRawRecord,
        );
        $check->popEvents();
        $em->persist($check);
    }

    #[Test]
    public function dmarcPolicyFlipRendersInlineDiffWithExpander(): void
    {
        $data = $this->bootClientWithDomain('dmarc-p-flip');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Day 0 baseline so the day-1 row is a "real" change (not initial).
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-2 days 09:00:00'),
            'v=DMARC1; p=none; rua=mailto:reports@example.com',
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        // Day 1: the actual `p=` flip. previousRawRecord is set so this row
        // qualifies as a real change and the differ runs against it.
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-1 day 09:00:00'),
            'v=DMARC1; p=quarantine; rua=mailto:reports@example.com',
            isValid: true,
            hasChanged: true,
            previousRawRecord: 'v=DMARC1; p=none; rua=mailto:reports@example.com',
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // The inline diff must render BOTH the struck-through old tag AND
        // the highlighted new tag in the SAME rendered code block — that's
        // the "what changed at a glance" payoff TASK-127 ships.
        self::assertMatchesRegularExpression(
            '/<span class="[^"]*bg-error\/20[^"]*line-through[^"]*">p=none<\/span>/',
            $body,
            'Removed `p=none` token must render with bg-error/20 + line-through.',
        );
        self::assertMatchesRegularExpression(
            '/<span class="[^"]*bg-success\/20[^"]*font-bold[^"]*">p=quarantine<\/span>/',
            $body,
            'Added `p=quarantine` token must render with bg-success/20 + font-bold.',
        );

        // Both spans must live INSIDE the same `<code>` block so the user
        // reads them on the same line, not in two separate before/after blobs.
        $codeBlockStart = strpos($body, 'p=none</span>');
        self::assertNotFalse($codeBlockStart, 'Removed-token span must be present.');
        $codeBlockSurround = substr($body, max(0, $codeBlockStart - 600), 1200);
        self::assertStringContainsString('p=quarantine</span>', $codeBlockSurround, 'Added-token span must appear next to the removed-token span in the same diff block.');

        // The `<details>` expander toggles the full before/after view. It
        // must be present AND default-collapsed (no `open` attribute on the
        // expander summary's parent). Match the exact summary copy + `<details`
        // without `open=`.
        self::assertMatchesRegularExpression(
            '/<details class="mt-2 group">\s*<summary[^>]*>.*?Show full before\/after/s',
            $body,
            'The full before/after `<details>` expander must be rendered and default-collapsed.',
        );

        // And the expander body must contain the literal Before + After labels
        // wired to the previous + current raw records.
        self::assertStringContainsString('>Before<', $body);
        self::assertStringContainsString('>After<', $body);
    }

    #[Test]
    public function initialCheckRowDoesNotRenderTheInlineDiff(): void
    {
        $data = $this->bootClientWithDomain('initial-no-diff');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Baseline only — `hasChanged=true` on the row (legacy pipeline
        // quirk fixed at the query layer by TASK-125), but no prior
        // observation, so `isRealChange` is FALSE and the diff must NOT run.
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-1 day'),
            'v=DMARC1; p=none',
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // The diff highlighting CSS must not appear at all — initial-check
        // rows render the plain code block from the existing template branch.
        self::assertStringNotContainsString('bg-error/20', $body);
        self::assertStringNotContainsString('bg-success/20', $body);
        self::assertStringNotContainsString('Show full before/after', $body);
        // The baseline record body still renders as a plain `<code>` block.
        self::assertStringContainsString('v=DMARC1; p=none', $body);
    }
}
