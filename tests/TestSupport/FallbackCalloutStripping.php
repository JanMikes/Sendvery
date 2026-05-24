<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

/**
 * Shared helpers for the regression net that locks unqualified
 * mailbox-first copy ("Connect a mailbox" / "Add mailbox" / "Connect
 * mailbox") to the single page element where it's allowed: the
 * `data-testid="fallback-callout"` card on `/app/mailboxes`.
 *
 * The naive approach — strip `<div data-testid="fallback-callout">...
 * </div>` with a regex — silently breaks the moment the callout's nesting
 * depth changes (card → card-body → card-actions → anchor is already four
 * deep). This trait extracts the callout via DOM so it stays correct
 * regardless of how deep the structure grows.
 *
 * Both {@see \App\Tests\Integration\Controller\ReportIngestionPageTest}
 * and {@see \App\Tests\Integration\Controller\MailboxesListTest} use it
 * so the two regression nets stay structurally identical.
 */
trait FallbackCalloutStripping
{
    /**
     * Removes the `data-testid="fallback-callout"` element (and everything
     * inside it) AND the layout-level "global add" dropdown from the
     * supplied HTML, returning the remaining page-content surface.
     *
     * The global add dropdown is exempted because it's a layout-level
     * affordance present on every dashboard page — it's not part of any
     * individual page's content hierarchy and is governed by a separate
     * decision than the per-page mailbox-first copy ban.
     *
     * Implementation: parse the page once, drop the targeted nodes from the
     * DOM, then re-serialise. The re-serialisation reformats whitespace and
     * void elements, which is fine — the caller only asserts substring
     * containment against the result, never byte equality.
     */
    private function stripFallbackCalloutAndGlobalDropdown(string $html): string
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // The XML declaration nudges DOMDocument into treating the input as
        // UTF-8 instead of assuming ISO-8859-1, which otherwise corrupts any
        // non-ASCII content in saveHTML output.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        // Remove the fallback callout entirely — N-level-deep-safe.
        $fallback = $xpath->query('//*[@data-testid="fallback-callout"]');
        assert($fallback instanceof \DOMNodeList);
        foreach ($fallback as $node) {
            assert($node instanceof \DOMNode);
            $node->parentNode?->removeChild($node);
        }

        // Remove the layout-level global add dropdown — `<details
        // class="dropdown dropdown-end">`. No testid on it because it's part
        // of the shared layout, not this page's content.
        $dropdowns = $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " dropdown ") and contains(concat(" ", normalize-space(@class), " "), " dropdown-end ")]');
        assert($dropdowns instanceof \DOMNodeList);
        foreach ($dropdowns as $node) {
            assert($node instanceof \DOMNode);
            $node->parentNode?->removeChild($node);
        }

        $serialized = $dom->saveHTML();
        assert(is_string($serialized));

        return $serialized;
    }
}
