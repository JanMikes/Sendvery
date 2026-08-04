<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Onboarding;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Entering a domain another team already monitors, during onboarding.
 *
 * `OnboardingDomainController` sends that case to `/app/domain-taken`, which is
 * the page built to explain it — join the existing team, or ping an admin if
 * you are the rightful owner. But `domain_taken` was not in the
 * `OnboardingRedirectListener` allowlist, so the listener immediately bounced
 * the user back to `nextStepRoute()` — the domain form again, blank, with no
 * message of any kind.
 *
 * There was no way out: the person could not finish onboarding with that domain
 * and was never told why. Found by walking the signup journey end to end.
 */
final class OnboardingDomainTakenDeadEndTest extends WebTestCase
{
    #[Test]
    public function aDomainOwnedByAnotherTeamExplainsItselfInsteadOfLoopingBackToTheForm(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $stranger = $fixtures->persona()->emailPrefix('stranger')->withDomain('contested.example')->build();
        assert(null !== $stranger->domain);
        $newcomer = $fixtures->persona()->emailPrefix('newcomer')->notOnboarded()->build();
        $client->loginUser($newcomer->user);

        $client->request('POST', '/app/onboarding/domain', ['domain_name' => $stranger->domain->domain]);

        self::assertResponseRedirects('/app/domain-taken?domain='.$stranger->domain->domain);

        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful('The explanation page must actually render mid-onboarding.');
        self::assertStringContainsString(
            'already monitored',
            $crawler->filter('body')->text(),
            'The person has to learn why their domain was refused.',
        );
    }

    #[Test]
    public function theyCanStillAskAnAdminForHelpFromThatPage(): void
    {
        // The page's whole purpose is the two escape hatches it offers. If the
        // POST behind "ping an admin" is bounced by the same listener, the page
        // renders but does nothing.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $stranger = $fixtures->persona()->emailPrefix('stranger')->withDomain('contested.example')->build();
        assert(null !== $stranger->domain);
        $newcomer = $fixtures->persona()->emailPrefix('newcomer')->notOnboarded()->build();
        $client->loginUser($newcomer->user);

        $client->request('POST', '/app/domain-taken/notify-admin', [
            'domain' => $stranger->domain->domain,
            '_csrf_token' => 'ignored-in-test',
        ]);

        // Reaching its own controller is the point: that controller answers with
        // its own redirect back to the explanation page, rather than the
        // listener hijacking the request to /app/onboarding/team.
        self::assertResponseRedirects('/app/domain-taken?domain='.$stranger->domain->domain);
    }
}
