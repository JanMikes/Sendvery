# Competitive Landscape

**Last updated:** 2026-03-24

## Paid SaaS Tools

| Tool | Free Tier | Starting Price | Notes |
|------|-----------|---------------|-------|
| **dmarcian** | Personal use only | $19.99/mo (2 domains, 100k msgs) | Well-established, $69/mo business tier |
| **EasyDMARC** | 1 domain, 1k emails | $35.99/mo (2 domains, 100k emails) | Broad feature set |
| **PowerDMARC** | Limited | $8/mo (50k emails) | Cheapest entry point |
| **Valimail** | Basic monitoring | $19/mo (Align, 500k emails) | Strong enterprise play, ~$2k/yr for Enforce |
| **DMARC Report** | No | $100/mo (25 domains, 2M msgs) | Targets bigger customers |
| **Postmark DMARC Digests** | Basic weekly digest | $14/mo/domain | Simple, clean, from Postmark |

### Key Observations
- Most tools charge per domain + per message volume
- Free tiers are heavily limited (1 domain, low volume)
- Pricing jumps quickly once you need multiple domains
- **None of them offer AI-powered analysis or recommendations**
- Most focus purely on DMARC, not broader email health

## Open Source / Self-Hosted

| Tool | Language | Approach | Pros | Cons |
|------|----------|----------|------|------|
| **parsedmarc** | Python | IMAP/Graph/Gmail → Elasticsearch/Splunk | Feature-rich, active development | Complex setup (needs ES + Kibana/Grafana) |
| **parse-dmarc (Go)** | Go | Single binary, Vue.js, SQLite | Zero deps, lightweight | Newer, smaller community |
| **dmarcts-report-parser** | Perl | IMAP → MySQL/PostgreSQL | Battle-tested | Perl, dated UI |
| **Open DMARC Analyzer** | PHP | Web UI on MariaDB | Full-featured UI | Requires separate parser |
| **dmarc-visualizer** | Docker | Parsedmarc + ES + Grafana bundle | One-command deploy | Heavy stack for what it does |

### Key Observations
- parsedmarc is the 800-lb gorilla — already does IMAP ingestion
- Self-hosted options require technical skill to deploy and maintain
- **Gap: nothing provides AI analysis or plain-English summaries**
- **Gap: nothing combines DMARC with SPF/DKIM/HTML checking**

## Where We Fit

### Our differentiators:
1. AI-powered insights (no competitor does this)
2. All-in-one email health (not just DMARC)
3. Lower friction than self-hosted (no Elasticsearch required)
4. More affordable than SaaS incumbents
5. HTML email quality analysis as bonus feature

### Our risks:
- parsedmarc is free and good enough for technical users
- Incumbent SaaS tools have brand recognition and trust
- AI features could be seen as gimmick if not genuinely useful
- Email authentication market is not huge
