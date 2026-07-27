<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A DMARC policy and a message disposition share three words — none,
 * quarantine, reject — and mean opposite things by them.
 *
 * A *disposition* reports what a receiver already did to a message: `reject`
 * means the mail was refused, which is bad news for the sender and earns a red
 * badge. A *policy* is what the domain owner asks receivers to do: `p=reject`
 * is full enforcement, the strongest posture available, the destination the
 * managed auto-ramp (DEC-058) drives paying customers toward, and what the
 * knowledge base calls "the goal you should work toward".
 *
 * THE DEFECT THIS EXISTS FOR: both went through `<twig:StatusBadge>`, whose map
 * is keyed for disposition. So a domain that had done everything right and
 * reached full enforcement wore a red error badge next to its name on the
 * domain detail page, the domains list card, and every report detail page —
 * while `p=none`, the state whose own explainer text reads "Anyone can spoof
 * your domain right now", wore neutral grey. The severity scale was inverted
 * for the one number a domain owner checks most.
 *
 * `<twig:PolicyBadge>` now owns policy and scales by exposure. This guard stops
 * policy from being routed back through the disposition map.
 *
 * WHAT IS DELIBERATELY NOT GUARDED: `<twig:StatusBadge status="{{ record.disposition }}">`
 * and the SPF/DKIM result badges. Those are outcomes, StatusBadge is correct for
 * them, and they legitimately use the same three words.
 */
final class PolicyBadgeVocabularyGuardTest extends TestCase
{
    /**
     * A `label` of `p=…` or `sp=…` is the unambiguous tell that a tag is
     * rendering a published policy rather than an outcome: RFC 7489 names the
     * policy tags `p` and `sp`, and no disposition is ever labelled that way.
     */
    private const string POLICY_LABEL_PATTERN = '#\bs?p=#i';

    #[Test]
    public function noTemplateRendersAPublishedPolicyThroughTheDispositionBadge(): void
    {
        $offenders = [];

        foreach ($this->templates() as $path => $contents) {
            foreach (ProjectSource::openingTags($contents, 'twig:StatusBadge') as $tag) {
                $label = ProjectSource::attributeValue($tag['source'], 'label') ?? '';

                if (1 !== preg_match(self::POLICY_LABEL_PATTERN, $label)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d — %s',
                    $path,
                    ProjectSource::lineOfOffset($contents, $tag['offset']),
                    trim($tag['source']),
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A published DMARC policy is being rendered through the disposition badge.

                StatusBadge's colour map answers "what happened to this message?", where
                reject means blocked and is red. A policy answers "what does this domain ask
                receivers to do?", where p=reject is full enforcement and the best outcome
                available. Routing policy through that map paints the strongest posture red
                and the weakest one neutral grey, so a domain owner who has just finished the
                ramp is told their success is an error.

                Use <twig:PolicyBadge policy="…" label="p=…" /> instead.

                Offending tags:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyPolicyTheEnumDefinesHasAToneAndNoneOfThemIsAnError(): void
    {
        // The positive half: banning the wrong component is useless if the
        // replacement leaves a policy unmapped (silently falling through to the
        // neutral default) or reintroduces an error tone.
        $badge = (string) file_get_contents(
            ProjectSource::projectDir().'/templates/components/PolicyBadge.html.twig',
        );

        self::assertStringNotContainsString(
            'badge-error',
            $badge,
            'No DMARC policy is an error. p=none is an exposure to act on and p=reject is the goal; neither is a fault, and the unrecognised-value arm must stay neutral.',
        );

        foreach (DmarcPolicy::cases() as $policy) {
            self::assertMatchesRegularExpression(
                sprintf("#'%s':\\s*'badge-#", preg_quote($policy->value, '#')),
                $badge,
                sprintf('PolicyBadge must map p=%s explicitly. An unmapped policy falls through to the neutral default, which silently understates whatever exposure it carries.', $policy->value),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        $templates = [];

        foreach (ProjectSource::files('templates', 'twig') as $path => $contents) {
            $templates[$path] = ProjectSource::stripTwigComments($contents);
        }

        self::assertNotSame([], $templates, 'The template scan found no files, so this guard would pass vacuously.');

        return $templates;
    }
}
