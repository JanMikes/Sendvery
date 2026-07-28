<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\Dns\FakeDns;
use App\Services\Dns\FakeSmtpProbe;
use App\Services\Dns\MxChecker;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/**
 * The MX checker's own words: "This is usually a network restriction on the
 * checking side rather than a problem with your mail servers" — carried at
 * `IssueSeverity::Info`, deliberately not gating MX validity.
 *
 * The per-record column has to agree with that. A red badge next to a sentence
 * saying "this is probably us, not you" is two surfaces describing the same
 * measurement, one of them as a catastrophe — the case CLAUDE.md settles by
 * calling the alarming one the bug.
 *
 * This became more than a tone question once IPv6 bracketing was fixed: before
 * it, an IPv6-only mail host was dialled at an address that cannot exist, so
 * the red "No" was reporting our own bug as the user's broken mail server.
 */
final class MxReachabilityToneTest extends WebTestCase
{
    #[Test]
    public function aMailHostWeCouldNotReachFromOurEgressIsNotRenderedAsAFailure(): void
    {
        self::createClient();

        $dns = (new FakeDns())
            ->withMx('example.com', 'mail.example.com')
            ->withA('mail.example.com', '192.0.2.10');

        $result = (new MxChecker($dns, (new FakeSmtpProbe())->withUnreachable('192.0.2.10')))->check('example.com');

        $html = $this->renderMxResults($result);

        self::assertStringContainsString(
            'usually a network restriction on the checking side',
            $html,
            'The issue list has to keep saying the restriction is probably ours — reachability does not gate MX validity.',
        );
        self::assertStringNotContainsString(
            'badge-error',
            $html,
            'A failed port-25 probe from our own egress is not the user\'s failure. Rendering it in the error tone '
            .'contradicts the informational note directly underneath it and teaches the user to discount both.',
        );
        self::assertStringContainsString(
            'No answer',
            $html,
            'A blank cell reads as broken too. Say what we observed: we asked and nothing came back.',
        );
        self::assertStringNotContainsString('Not probed', $html, 'We did probe this host — it simply did not answer.');
    }

    #[Test]
    public function aMailHostWhoseAddressWeCouldNotResolveSaysWeNeverAsked(): void
    {
        self::createClient();

        // MX record present, but no A and no AAAA for the target, so MxChecker
        // never gets an address to probe. "We have not asked" is a third state,
        // and it used to render in the same red as a host that refused us.
        $dns = (new FakeDns())->withMx('example.com', 'mail.example.com');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        $html = $this->renderMxResults($result);

        self::assertStringContainsString('Not probed', $html, 'Never having asked is not the same answer as having been refused.');
        self::assertStringNotContainsString('No answer', $html);
        self::assertStringNotContainsString('badge-error', $html, 'An unprobed host is the "unknown is not failure" case in its purest form.');
    }

    #[Test]
    public function aMailHostThatAnsweredIsStillRenderedAsAPass(): void
    {
        self::createClient();

        $dns = (new FakeDns())
            ->withMx('example.com', 'mail.example.com')
            ->withA('mail.example.com', '192.0.2.10');

        $result = (new MxChecker($dns, (new FakeSmtpProbe())->withReachable('192.0.2.10', tlsSupported: true)))->check('example.com');

        $html = $this->renderMxResults($result);

        self::assertStringContainsString('badge-success', $html, 'Reaching the mail server is a good outcome and must read as one.');
        self::assertStringNotContainsString('usually a network restriction', $html);
    }

    private function renderMxResults(object $result): string
    {
        $twig = self::getContainer()->get(Environment::class);
        \assert($twig instanceof Environment);

        return $twig->render('tools/_results/mx-results.html.twig', [
            'result' => $result,
            'domain' => 'example.com',
        ]);
    }
}
