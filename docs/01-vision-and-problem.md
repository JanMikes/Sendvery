# Vision & Problem Statement

**Last updated:** 2026-03-24

## The Problem

Email authentication is critical but poorly understood by most domain owners. DMARC reports arrive as compressed XML files that are practically unreadable without tooling. The result: most people either ignore them entirely or pay for expensive SaaS tools that provide dashboards but little actionable guidance.

### Pain Points

- DMARC reports are XML inside .zip/.gz attachments — nobody reads them manually
- Existing free tools are either limited or require complex self-hosting (Elasticsearch + Grafana stacks)
- Paid tools start at $20-100/mo for what amounts to "charts of your XML data"
- SPF, DKIM, DMARC, BIMI, MTA-STS are fragmented — no single tool covers all of email auth well
- No existing tool provides AI-powered "here's what this means and what to do" guidance
- HTML email quality checking requires yet another separate tool

## Target Users

### Phase 1: Personal / Developer
- Developers and sysadmins managing their own domains
- People who know they *should* look at DMARC reports but don't
- Self-hosters who want control over their data

### Phase 2: Small Business / Freelancer
- Small businesses managing 1-5 domains
- Freelance developers managing email for clients
- Marketing teams who want to ensure deliverability

### Phase 3: Agency / MSP
- Agencies managing email for multiple clients
- Managed service providers
- IT consultants

## User Personas

### Persona 1: "Jan the Developer" (Phase 1 — primary)
- **Who:** Solo developer / small company founder, manages 2-5 domains, technical, runs own servers
- **Situation:** Receives DMARC reports daily, deletes them. Knows it's wrong but the XML is unreadable and there's always something more urgent. Set up SPF/DKIM/DMARC once, hasn't touched it since.
- **Trigger:** Something breaks — email lands in spam, a client mentions phishing from his domain, or he reads an article about email spoofing and gets nervous.
- **Goal:** "I want to understand what's happening with my email auth without spending an hour on XML files."
- **Willingness to pay:** $5-10/mo if it saves him time and worry. Would prefer self-hosting but will pay for hosted if it just works.
- **Objections:** "I could probably parse this myself with a script." "Is this really worth paying for monthly?" "Can I trust a third party with my IMAP credentials?"
- **Conversion path:** Google "dmarc checker" → tool page → checks domain → sees issues → "want ongoing monitoring?" → beta signup → tries product → sees value in first week → pays
- **Retention driver:** Weekly digest showing what changed. The moment he stops getting value, he cancels.

### Persona 2: "Marketing Maria" (Phase 2)
- **Who:** Marketing manager or founder at small business (10-50 employees), manages company email + newsletter. Not deeply technical but understands DNS basics.
- **Situation:** Company sends newsletters via Mailchimp, transactional via SendGrid, support via Zendesk. Email deliverability is dropping but nobody knows why. IT "set up the DNS stuff" a year ago.
- **Trigger:** Newsletter open rates dropping, emails bouncing, or a deliverability audit from their ESP.
- **Goal:** "I need someone to tell me in plain English what's wrong and how to fix it."
- **Willingness to pay:** $5.99-9.98/mo without hesitation if it solves the deliverability problem. AI add-on is very attractive — she doesn't want to learn SPF syntax.
- **Objections:** "Is this going to be too technical for me?" "Can't my hosting provider do this?"
- **Conversion path:** Google "why is my email going to spam" → knowledge base article → email auth checker → sees red flags → "Get this explained in plain English with AI" → signup → AI tells her exactly what DNS records to change → magic moment
- **Retention driver:** AI digest + alerts. She'll never learn to read raw reports, so AI is the ongoing value.

### Persona 3: "Agency Alex" (Phase 3)
- **Who:** IT consultant, MSP, or digital agency managing email for 10-50 clients. Very technical.
- **Situation:** Manages DNS and email config for multiple client domains. Currently checks manually or uses expensive tools. Clients occasionally ask "is our email secure?" and he has no quick answer.
- **Trigger:** Needs to justify his retainer. Wants a dashboard he can show clients. Or a client gets phished and he needs to prove it wasn't his fault.
- **Goal:** "I need one dashboard for all my clients' domains with alerts when something breaks."
- **Willingness to pay:** $49.99/mo is cheap compared to alternatives (dmarcian Business is $69+). Would pay more for white-label.
- **Objections:** "Can I brand this as my own?" "Does it scale to 100+ domains?" "API access?"
- **Conversion path:** Google "dmarc monitoring tool for agencies" or referral → pricing page → Team tier → trial → adds 5 client domains → value proven → pays → adds more domains over time
- **Retention driver:** Client reporting. Once his workflow depends on Sendvery, switching cost is high.

## Value Proposition

**One-liner:** "Understand your email authentication in plain English — not XML."

**For developers:** Stop deleting DMARC reports. See what's happening with your email auth in 30 seconds.

**For small business:** Your email deliverability, explained. AI-powered insights tell you exactly what to fix.

**For agencies:** One dashboard for all your clients' domains. Monitoring, alerts, and reports that justify your retainer.

### What makes this different from existing tools:
1. **AI-first analysis** — Claude-powered insights, not just dashboards
2. **All-in-one** — DMARC + SPF + DKIM + HTML analysis in one place
3. **Personal-first pricing** — free tier that's actually useful, paid tiers that are affordable
4. **Two ingestion methods** — IMAP connection to existing mailbox OR dedicated receiving address
5. **Security expertise built in** — tool pages educate on risks and attack vectors, not just show results
6. **Open source** — self-host free forever, no vendor lock-in

## Non-Goals (for now)

- Not an email sending service
- Not a full email client
- Not competing with enterprise solutions (Agari, Proofpoint, etc.)
