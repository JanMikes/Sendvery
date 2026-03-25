# Open Questions

**Last updated:** 2026-03-24

Questions that need to be resolved before or during implementation.

---

## Tech Stack (mostly resolved)
- [x] ~~Backend language/framework~~ → PHP 8.5 + Symfony 8 + FrankenPHP (DEC-008, superseded by DEC-022)
- [x] ~~Database~~ → PostgreSQL 16 (DEC-010)
- [x] ~~Queue system~~ → Symfony Messenger, Doctrine transport (DEC-010)
- [x] ~~Email sending~~ → Symfony Mailer via Seznam SMTP (DEC-011)
- [x] ~~Error tracking~~ → Sentry (DEC-014)
- [x] ~~Frontend JS approach?~~ → Stimulus + Turbo / Hotwire (DEC-018)
- [ ] **IMAP/POP3 library?** Top 3: Horde/Imap_Client (native PHP, both protocols), barbushin/php-imap (needs ext-imap from PECL), Webklex/php-imap (native IMAP, POP3 needs ext-imap). Must support POP3. ext-imap installable via PECL in Docker. Decide during vibecoding. See 10-libraries-and-tools.md for full comparison.
- [x] ~~PHP version?~~ → PHP 8.5 (DEC-022)
- [x] ~~Nginx or Caddy?~~ → FrankenPHP (built-in Caddy, no separate web server needed) (DEC-022)

## Product
- [x] ~~Project name?~~ → Sendvery (DEC-021). Domain purchase still needed.
- [x] ~~Open source or closed source?~~ → Open source (DEC-015)
- [x] ~~Self-hosted option?~~ → Always free self-hosted tier (DEC-015)
- [x] ~~License?~~ → AGPL-3.0 (DEC-017)

## Business
- [x] ~~Per-domain or per-volume pricing?~~ → Per-domain tiers (DEC-024)
- [x] ~~When to start charging?~~ → After closed beta validation, Phase 2 (see 03-features-roadmap.md)
- [ ] **Legal considerations?** DMARC aggregate reports contain IPs. Forensic reports (ruf) will be supported with PII redaction. Privacy policy must cover both. GDPR data export/deletion needed.
- [x] ~~Lifetime deal for early adopters?~~ → No. Monthly/annual subscriptions only. No future liability.
- [x] ~~Stripe plan structure?~~ → Documented in 05-monetization.md (Products & Prices structure)
- [ ] **EU VAT handling?** Jan is not a VAT payer (under CZ threshold). For B2C digital services to EU, OSS registration may be needed eventually. Defer until revenue justifies the admin overhead. Stripe Tax can be added later without code changes.
- [x] ~~Business entity registration in CZ?~~ → Already has živnostenský list (OSVČ). Can receive Stripe payouts. Not a VAT payer.

## Technical
- [x] ~~How to handle DMARC forensic reports (ruf)?~~ → Support them. Parse forensic reports with PII handling (redact/hash sensitive fields like email addresses and subjects). Shows failure details without storing raw PII. Add note in privacy policy about ruf data handling.
- [ ] **Rate limiting on AI analysis?** Claude API costs could spike with many users
- [ ] **How to do email HTML analysis?** Build own checker vs integrate existing tools?
- [ ] **Blacklist API providers?** Free APIs vs paid services?
- [ ] **IMAP credential encryption implementation?** Doctrine column-level encryption? Separate encrypted fields? Which library?
- [x] ~~OAuth2 for Gmail/Microsoft?~~ → Yes, implement from the start. Support OAuth2 for Gmail and Microsoft 365 alongside password-based IMAP. Requires registering as OAuth app with Google and Microsoft. Better UX and necessary since Gmail restricts "less secure app" access.

## Testing
- [ ] **Mutation testing?** Use Infection from the start or add later? (Recommended: from start — see 10-libraries-and-tools.md)
- [ ] **E2E testing approach?** Panther (Symfony's browser testing) or separate tool?
- [ ] **Test IMAP integration?** Mock IMAP server in tests, or use real test mailbox?
- [x] ~~CI system?~~ → GitHub Actions (DEC-020)

## Marketing
- [x] ~~Landing page before product?~~ → Yes, fake door strategy (DEC-026)
- [x] ~~Blog / content strategy timeline?~~ → Knowledge base, not blog (DEC-030). Write at launch, no cadence.
- [x] ~~Domain name?~~ → sendvery.com (DEC-021). Purchase still needed.

---

*Move questions to decisions-log.md once resolved*
