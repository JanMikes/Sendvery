<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A DMARC policy badge must carry the severity of the domain's exposure, and
 * DMARC policy strength ascends none -> quarantine -> reject.
 *
 * `p=reject` is full enforcement: spoofed mail is refused outright. It is the
 * best available outcome, the destination the managed auto-ramp (DEC-058)
 * drives paying customers toward, and the state the knowledge base calls "the
 * goal you should work toward". `p=none` is monitor-only: the domain detail
 * page itself tells the reader "Anyone can spoof your domain right now".
 *
 * Both policies used to render through the shared `<twig:StatusBadge>` colour
 * map, which is keyed for message *disposition* — what a receiver did to a
 * message — where `reject` legitimately means "blocked" and earns a red badge.
 * Sharing that map inverted the scale for policy: the strongest posture showed
 * a red error badge beside the domain name while the weakest showed neutral
 * grey. That is the "a desired outcome must never render as a warning" rule in
 * CLAUDE.md, and it read to users as though reaching full enforcement had
 * broken something.
 *
 * These assertions name daisyUI semantic tokens deliberately. CLAUDE.md forbids
 * asserting utility classes for *styling*, but permits semantic tokens where
 * the token IS the business rule under test - severity mapping is that case.
 */
final class PolicyBadgeSeverityTest extends WebTestCase
{
    #[Test]
    public function fullEnforcementIsNeverPresentedAsAnError(): void
    {
        $badge = $this->policyBadgeFor(DmarcPolicy::Reject);

        self::assertStringNotContainsString(
            'badge-error',
            $badge,
            'A domain at p=reject has the strongest possible DMARC posture. Presenting it in the error tone tells the owner their best outcome is a failure.',
        );
        self::assertStringContainsString(
            'badge-success',
            $badge,
            'Full enforcement is the goal the product drives users toward, so it must read as success.',
        );
    }

    #[Test]
    public function monitorOnlyIsPresentedAsNeedingAttention(): void
    {
        $badge = $this->policyBadgeFor(DmarcPolicy::None);

        self::assertStringContainsString(
            'badge-warning',
            $badge,
            'p=none leaves the domain spoofable, which the page states in words. A neutral badge understates a real exposure.',
        );
    }

    #[Test]
    public function gradualEnforcementSitsBetweenTheTwoExtremes(): void
    {
        $badge = $this->policyBadgeFor(DmarcPolicy::Quarantine);

        self::assertStringNotContainsString(
            'badge-error',
            $badge,
            'p=quarantine is enforcement, not a fault.',
        );
        self::assertStringNotContainsString(
            'badge-success',
            $badge,
            'p=quarantine is not yet full enforcement, so it must not read as a finished, successful state.',
        );
    }

    /**
     * Rendering through the real controller keeps the contract honest: it is
     * the domain detail page that must stop calling the disposition-keyed map.
     */
    private function policyBadgeFor(DmarcPolicy $policy): string
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $domain = $persona->domain;
        assert($domain instanceof MonitoredDomain);

        $em = $this->getService(EntityManagerInterface::class);
        $domain->dmarcPolicy = $policy;
        $em->flush();

        $crawler = $this->visitDomainDetail($client, $domain);

        $badge = $crawler->filter(sprintf('span.badge:contains("p=%s")', $policy->value));

        self::assertGreaterThan(
            0,
            $badge->count(),
            sprintf('The domain header must show the published policy p=%s.', $policy->value),
        );

        return (string) $badge->first()->attr('class');
    }

    private function visitDomainDetail(KernelBrowser $client, MonitoredDomain $domain): Crawler
    {
        $crawler = $client->request('GET', '/app/domains/'.$domain->id->toString());

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
