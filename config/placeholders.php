<?php

declare(strict_types=1);

// TODO(placeholder): see docs/cx-improvement-backlog.md TASK-023 — entire file is launch-swap content.
// Swap convention: every fake entry carries an inline marker comment on the
// same line as the array entry opening (the literal marker phrase is enforced
// by the convention test — see PlaceholdersConventionTest::PLACEHOLDER_MARKER).
// Run
//   grep "TODO(placeholder)" config/placeholders.php
// before launch — every hit is a string a human still has to read and replace.
// A unit test (tests/Unit/Config/PlaceholdersConventionTest.php) enforces that
// the marker count stays in sync with the testimonial count, so a partial swap
// that strips the marker on only some entries fails CI.

return [
    'founder_photo' => null, // TODO(placeholder): replace with real photo URL or asset path before launch (TASK-024)
    'linkedin_url' => null, // TODO(placeholder): replace with real LinkedIn profile URL before launch (TASK-024)
    'testimonials' => [
        [ // TODO(placeholder): replace before launch
            'quote' => 'We sat at p=none for 14 months because nobody could read the XML. Sendvery flagged that our Mailchimp subdomain was sending unsigned the first morning we connected it — we went to p=quarantine three weeks later.',
            'name' => 'Maya Hernandez',
            'role' => 'Head of Deliverability',
            'company' => 'Lattice Mail',
            'initials' => 'MH',
        ],
        [ // TODO(placeholder): replace before launch
            'quote' => 'Our SPF record was one include away from the 10-lookup limit and we had no idea. Sendvery counted them, named the included domain that was bloating it, and told me which one we no longer needed.',
            'name' => 'Tomáš Novák',
            'role' => 'Platform Engineer',
            'company' => 'Forkbox',
            'initials' => 'TN',
        ],
        [ // TODO(placeholder): replace before launch
            'quote' => 'After a DNS migration our DKIM selector silently went missing on the marketing subdomain. The weekly digest caught the drop in pass-rate before the next campaign send — we would have burned a Tuesday otherwise.',
            'name' => 'Priya Iyer',
            'role' => 'IT Director',
            'company' => 'Northwind Logistics',
            'initials' => 'PI',
        ],
        [ // TODO(placeholder): replace before launch — bench entry, not rendered in the default 3-card grid
            'quote' => 'I had three Postmark servers, a SendGrid account, and a transactional service nobody had documented all sending as us. Sendvery split them out by source IP within a day. I finally know who is sending on our behalf.',
            'name' => 'David Okafor',
            'role' => 'DevOps Lead',
            'company' => 'RouteSignal',
            'initials' => 'DO',
        ],
        [ // TODO(placeholder): replace before launch — bench entry, not rendered in the default 3-card grid
            'quote' => 'We were quietly inheriting a weaker DMARC policy on a subdomain than on the apex. Sendvery surfaced the inheritance, suggested the explicit subdomain record, and our reject rollout actually meant reject everywhere.',
            'name' => 'Anna Lindqvist',
            'role' => 'CTO',
            'company' => 'Klippa Studio',
            'initials' => 'AL',
        ],
        [ // TODO(placeholder): replace before launch — bench entry, not rendered in the default 3-card grid
            'quote' => 'The thing that won me over: I get a single A–F grade per domain and a short list of what would move it up. I do not have to interpret aggregate XML to know what to fix on Monday morning.',
            'name' => 'Marco Bianchi',
            'role' => 'SRE',
            'company' => 'Telio Cloud',
            'initials' => 'MB',
        ],
    ],
];
