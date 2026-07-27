<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sender inventory speaks ONE vocabulary: Authorized / Needs review /
 * Not authorized.
 *
 * THE DEFECT THIS EXISTS FOR: the app used to have four words for two states.
 * The filter tab said "Unauthorized", the badge on the same row said "Unknown",
 * the summary chip above it said "unknown", the bulk button said "Mark unknown"
 * and the per-row button said "Revoke". A user looking at five amber pills could
 * not tell whether any two of those words meant the same thing, whether amber
 * was asking them to do something, or what pressing "Revoke" would break — and
 * because nothing is actually blocked by any of these decisions, the scariest
 * sounding word ("Revoke") was attached to the most harmless action. People
 * either froze or clicked Authorize on everything to make the amber go away,
 * which is precisely the outcome the inventory exists to prevent.
 *
 * {@see SenderReviewState} is now the single source of the wording. This guard
 * stops the retired words from creeping back into COPY.
 *
 * WHAT IS DELIBERATELY NOT GUARDED — identifiers, which are retained for
 * compatibility and must keep working:
 *   - `?filter=unauthorized`      old bookmarks and emailed links
 *   - `dashboard_sender_revoke`   route name
 *   - `mark_unknown`              form POST value
 *   - `recommend_revoke`          advisor severity enum value
 * The scan therefore removes `{{ … }}` and `{% … %}` first and only reads text
 * nodes plus the attributes a human sees or hears (`title`, `aria-label`,
 * `data-tip`, `alt`, `placeholder`). Route calls, `value="…"`, `id="…"` and
 * `data-testid="…"` are invisible to it by construction.
 *
 * ALSO NOT A VIOLATION: "Not identified", which is the intended wording for an
 * *organisation* Sendvery could not resolve from an IP. That is a different
 * concept from a sender's review state — the org is unknown to us, the sender's
 * state is unknown to the user — and it has no bearing on the three states.
 */
final class SenderVocabularyGuardTest extends TestCase
{
    /**
     * A template renders sender state if it touches one of these. Discovering
     * scope from content rather than from a hard-coded file list means a new
     * page that starts rendering sender status is guarded from its first commit.
     */
    private const array SENDER_STATE_MARKERS = [
        'SenderStatusBadge',
        'SenderStatusLegend',
        'SenderReviewCta',
        'senderIsAuthorized',
        'senderSummary',
        'needsReviewCount',
        'reviewState',
        'data-sender-state',
        'dashboard_sender_',
    ];

    /**
     * Each retired word, with the replacement to put in the failure message. An
     * engineer who trips this needs to know what to write instead, not just what
     * not to write.
     */
    private const array RETIRED_WORDS = [
        'Unknown' => 'Say "Needs review" when nobody has decided about the sender yet, or "Not tracked yet" when no inventory row backs it at all. "Unknown" answers neither "is this mine?" nor "must I do something?".',
        'Unauthorized' => 'Say "Not authorized" — the state a human chose — or "Needs review" if nobody has decided. "Unauthorized" reads as an enforcement action; Sendvery never blocks a message on the strength of this flag.',
        'Unauthorised' => 'Say "Not authorized" (and note the app spells it with a z throughout).',
        'Revoke' => 'Say "Mark not authorized". Nothing is revoked: no DNS record changes, no mail is blocked, and the sender keeps sending. "Revoke" promised an enforcement power the product does not have.',
        'Mark unknown' => 'Say "Mark not authorized" — it names the state the row moves to.',
    ];

    #[Test]
    public function noRetiredSenderWordSurvivesInUserFacingCopy(): void
    {
        $offenders = [];
        foreach ($this->senderFacingTemplates() as $path => $contents) {
            $copy = ProjectSource::visibleCopy($contents);

            foreach (self::RETIRED_WORDS as $word => $replacement) {
                if (1 !== preg_match('/\b'.preg_quote($word, '/').'\b/i', $copy, $match, \PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $offenders[] = sprintf('%s — "%s" is user-facing copy. %s', $path, (string) $match[0][0], $replacement);
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                Retired sender wording is back in copy the user reads.

                The inventory has exactly three states, and every surface must use
                SenderReviewState's words for them: Authorized, Needs review, Not authorized.
                The cost of a second vocabulary is not cosmetic — the same sender showing
                "Needs review" on one page and "Unauthorized" on another reads as two
                different findings about two different servers, and the user cannot tell which
                page to believe or whether the red one needs urgent action.

                Query values, route names and POST values keep the old spellings on purpose
                and are invisible to this scan; only what the user reads or hears is checked.

                Offending copy:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyStateTheEnumDefinesIsSpeltTheSameWayInCopy(): void
    {
        // The positive half of the guard: banning the old words is useless if the
        // new ones drift. The legend is the canonical explanation surface, so it
        // must render each state exactly as the enum labels it.
        $legend = ProjectSource::visibleCopy(
            (string) file_get_contents(ProjectSource::projectDir().'/templates/components/SenderStatusLegend.html.twig'),
        );

        foreach (SenderReviewState::cases() as $state) {
            self::assertStringContainsString(
                $state->label(),
                $legend,
                sprintf('The status legend must explain the "%s" state using its canonical label, or users meet a badge the legend never mentions.', $state->label()),
            );
        }
    }

    #[Test]
    public function theGuardCoversEverySenderFacingSurface(): void
    {
        // Scope is discovered from file contents, so it must be asserted: a
        // marker rename would silently shrink the scan to nothing.
        $scope = array_keys($this->senderFacingTemplates());

        foreach ([
            'templates/dashboard/sender_inventory.html.twig',
            'templates/dashboard/domain_detail.html.twig',
            'templates/dashboard/report_detail.html.twig',
            'templates/components/SenderStatusBadge.html.twig',
            'templates/components/SenderStatusLegend.html.twig',
            'templates/components/SenderReviewCta.html.twig',
            'templates/components/SenderActionCallout.html.twig',
            'templates/components/DomainWorkspaceTabs.html.twig',
        ] as $expected) {
            self::assertContains($expected, $scope, sprintf('%s renders sender state and must be inside the vocabulary guard.', $expected));
        }
    }

    #[Test]
    public function theGuardIgnoresIdentifiersItIsMeantToTolerate(): void
    {
        // Proof that the retained compatibility identifiers do NOT trip the
        // guard. Without this, the obvious "fix" for a future false positive
        // would be to delete the identifiers and break every old bookmark.
        $identifiersOnly = <<<'TWIG'
            <a href="{{ path('dashboard_sender_inventory', { filter: 'unauthorized' }) }}" data-testid="sender-legacy-unauthorized-tab">Everything not authorized</a>
            <button name="action" value="mark_unknown">Mark selected not authorized</button>
            <form id="revoke-form-1" action="{{ path('dashboard_sender_revoke', { senderId: sender.id }) }}"></form>
            {% if advice.severity.value == 'recommend_revoke' %}<span>Consider marking this sender not authorized</span>{% endif %}
            TWIG;

        self::assertSame([], self::retiredWordsIn($identifiersOnly));
    }

    #[Test]
    public function theGuardToleratesNotIdentifiedForAnUnresolvedOrganisation(): void
    {
        $organisationColumn = '<span title="We could not resolve who owns this IP.">Not identified</span>';

        self::assertSame([], self::retiredWordsIn($organisationColumn));
    }

    #[Test]
    public function vocabularyGuardItselfFailsOnEachRetiredWord(): void
    {
        self::assertSame(['Unknown'], self::retiredWordsIn('<span class="badge badge-warning">Unknown</span>'));
        self::assertSame(['Unauthorized'], self::retiredWordsIn('<span class="badge badge-error">Unauthorized</span>'));
        self::assertSame(['Revoke'], self::retiredWordsIn('<button class="btn btn-xs">Revoke</button>'));
        self::assertSame(['Unknown', 'Mark unknown'], self::retiredWordsIn('<button>Mark unknown</button>'));
        self::assertSame(['Unauthorized'], self::retiredWordsIn('<span aria-label="3 unauthorized senders waiting for triage">3</span>'), 'Copy hidden in an aria-label is still copy — screen-reader users read it.');
        self::assertSame([], self::retiredWordsIn('<span class="badge badge-warning">Needs review</span>'));
    }

    /**
     * Templates whose copy describes a sender's review state, discovered from
     * content so a new surface is covered automatically. Scoped to the
     * dashboard and its components: the marketing site and knowledge base
     * legitimately use "unauthorized senders" as ordinary English about email
     * spoofing, where it is not a state label attached to a row.
     *
     * @return array<string, string>
     */
    private function senderFacingTemplates(): array
    {
        $candidates = [
            ...ProjectSource::files('templates/dashboard', 'twig'),
            ...ProjectSource::files('templates/components', 'twig'),
        ];

        return array_filter($candidates, static function (string $contents, string $path): bool {
            // Any component named Sender* is about senders by definition, even
            // if it happens to render state through a prop this list cannot see.
            if (str_starts_with(basename($path), 'Sender')) {
                return true;
            }

            foreach (self::SENDER_STATE_MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return true;
                }
            }

            return false;
        }, \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return list<string> the retired words found in visible copy
     */
    private static function retiredWordsIn(string $twig): array
    {
        $copy = ProjectSource::visibleCopy($twig);

        $found = [];
        foreach (array_keys(self::RETIRED_WORDS) as $word) {
            if (1 === preg_match('/\b'.preg_quote($word, '/').'\b/i', $copy)) {
                $found[] = $word;
            }
        }

        return $found;
    }
}
