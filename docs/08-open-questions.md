# Open Questions

**Last updated:** 2026-05-14

Questions that need to be resolved before or during implementation.

---

## Tech Stack (mostly resolved)
- [x] ~~Backend language/framework~~ → PHP 8.5 + Symfony 8 + FrankenPHP (DEC-008, superseded by DEC-022)
- [x] ~~Database~~ → PostgreSQL 16 (DEC-010)
- [x] ~~Queue system~~ → Symfony Messenger, Doctrine transport (DEC-010)
- [x] ~~Email sending~~ → Symfony Mailer via Seznam SMTP (DEC-011)
- [x] ~~Error tracking~~ → Sentry (DEC-014)
- [x] ~~Frontend JS approach?~~ → Stimulus + Turbo / Hotwire (DEC-018)
- [x] ~~IMAP/POP3 library?~~ → **webklex/php-imap ^6.2** (DEC-049). Native PHP IMAP. POP3 not currently used by Sendvery; if needed later, install `ext-imap` from PECL.
- [x] ~~PHP version?~~ → PHP 8.5 (DEC-022)
- [x] ~~Nginx or Caddy?~~ → FrankenPHP (built-in Caddy, no separate web server needed) (DEC-022)

## Product
- [x] ~~Project name?~~ → Sendvery (DEC-021).
- [x] ~~Domain purchased?~~ → sendvery.com — purchased and pointed at the Hetzner host via Traefik (May 2026).
- [x] ~~Open source or closed source?~~ → Open source (DEC-015)
- [x] ~~Self-hosted option?~~ → Always free self-hosted tier (DEC-015)
- [x] ~~License?~~ → AGPL-3.0 (DEC-017)

## Business
- [x] ~~Per-domain or per-volume pricing?~~ → Per-domain tiers (DEC-024)
- [x] ~~When to start charging?~~ → Stripe checkout currently fake-doored behind a beta access request form (DEC-050). Real charging deferred until closed-beta validation.
- [ ] **Legal considerations?** DMARC aggregate reports contain IPs. Forensic reports (ruf) will be supported with PII redaction. Privacy policy must cover both. GDPR data export/deletion needed.
- [x] ~~Lifetime deal for early adopters?~~ → No. Monthly/annual subscriptions only. No future liability.
- [x] ~~Stripe plan structure?~~ → Documented in 05-monetization.md (Products & Prices structure)
- [x] ~~EU VAT handling?~~ → Jan is OSVČ in CZ and **not** a VAT payer (under the CZ ~CZK 2M threshold). All advertised hosted prices are "VAT included where applicable" — for an non-registered seller this means we charge a flat sticker price and do not break out VAT. If we later cross the threshold, the CZ revenue triggers VAT registration; for B2C digital services into other EU member states the OSS (One-Stop-Shop) regime applies. Stripe Tax can be enabled later without code changes. Until then, keep pricing in USD with the "VAT included" footnote on the pricing UI for honesty.
- [x] ~~Business entity registration in CZ?~~ → Already has živnostenský list (OSVČ). Can receive Stripe payouts. Not a VAT payer.

## Technical
- [x] ~~How to handle DMARC forensic reports (ruf)?~~ → Support them. Parse forensic reports with PII handling (redact/hash sensitive fields like email addresses and subjects). Shows failure details without storing raw PII. Add note in privacy policy about ruf data handling.
- [ ] **Rate limiting on AI analysis?** Claude API costs could spike with many users. Needed before AI add-on (Phase 3).
- [ ] **How to do email HTML analysis?** Build own checker vs integrate existing tools?
- [x] ~~Blacklist API providers?~~ → Free DNS-based RBLs only (DEC-051). Implemented via `jbboehr/dnsbl` against Spamhaus ZEN, Barracuda BRBL, SORBS, SpamCop. No paid commercial services. Re-evaluate if free RBLs prove too noisy or get rate-limited.
- [x] ~~IMAP credential encryption implementation?~~ → `paragonie/halite` (libsodium-backed) wrapped in `App\Services\CredentialEncryptor`. AES-256-GCM equivalent via XChaCha20-Poly1305. Key from `ENCRYPTION_KEY` env (32 bytes, base64).
- [x] ~~OAuth2 for Gmail/Microsoft?~~ → Yes, implement from the start. Support OAuth2 for Gmail and Microsoft 365 alongside password-based IMAP. Requires registering as OAuth app with Google and Microsoft. Better UX and necessary since Gmail restricts "less secure app" access.

## Testing
- [x] ~~Mutation testing?~~ → Infection wired from the start (`infection.json5`).
- [ ] **E2E testing approach?** Panther (Symfony's browser testing) or separate tool? Not blocking — controller-level WebTestCase coverage is sufficient for now.
- [x] ~~Test IMAP integration?~~ → `App\Services\Mail\FakeMailClient` swapped in via service alias under `when@test`. No real IMAP in tests.
- [x] ~~CI system?~~ → GitHub Actions (DEC-020)

## Marketing
- [x] ~~Landing page before product?~~ → Yes, fake door strategy (DEC-026)
- [x] ~~Blog / content strategy timeline?~~ → Knowledge base, not blog (DEC-030). Write at launch, no cadence.
- [x] ~~Domain name?~~ → sendvery.com (DEC-021). Purchased.

---

*Move questions to decisions-log.md once resolved*
