# Sendvery — Image Generation Guide

## How to Use

Each numbered file contains a **complete, self-contained prompt** for a fresh ChatGPT session. Start a new chat for each image to prevent style drift.

**Workflow:**
1. Open a fresh ChatGPT chat (GPT-4o with image generation)
2. Paste the entire contents of the prompt file
3. Generate 2-3 variations, pick the best
4. If the result isn't right, tell ChatGPT what to adjust (don't start over)
5. Download at max resolution
6. Save to `assets/images/` with the filename specified in each prompt file

## Generation Order

**Generate these first** — they set the visual tone and are needed earliest:

| Priority | File | Needed By |
|----------|------|-----------|
| 1 | `01-hero-background.md` | Stage 4 |
| 2 | `02-og-social-image.md` | Stage 4 |
| 3 | `03-background-pattern.md` | Stage 4 |
| 4-6 | `04-how-connect.md`, `05-how-monitor.md`, `06-how-act.md` | Stage 4 |
| 7-9 | `07-logo-envelope-pulse.md`, `08-logo-shield-mail.md`, `09-logo-plane-check.md` | Stage 4 |
| 10 | `10-email-header-banner.md` | Stage 6 |
| 11 | `11-security-shield.md` | Stage 4 |
| 12 | `12-health-score-badge.md` | Stage 5 |
| 13-17 | `13-empty-no-domains.md` through `17-empty-welcome.md` | Stage 9 |
| 18-20 | `18-onboard-team.md`, `19-onboard-domain.md`, `20-onboard-mailbox.md` | Stage 10 |
| 21 | `21-404-page.md` | Stage 4 |
| 22-24 | `22-kb-dmarc.md`, `23-kb-spf.md`, `24-kb-email-auth.md` | Stage 6 |

## File Naming Convention

Save generated images as:
```
assets/images/hero-bg.png
assets/images/og-default.png
assets/images/bg-pattern.png
assets/images/how-connect.png
assets/images/how-monitor.png
assets/images/how-act.png
assets/images/logo-concept-1.png
assets/images/logo-concept-2.png
assets/images/logo-concept-3.png
assets/images/email-header.png
assets/images/security-shield.png
assets/images/health-score-a.png
assets/images/empty-no-domains.png
assets/images/empty-no-reports.png
assets/images/empty-all-clear.png
assets/images/empty-no-mailbox.png
assets/images/empty-welcome.png
assets/images/onboard-team.png
assets/images/onboard-domain.png
assets/images/onboard-mailbox.png
assets/images/404.png
assets/images/kb-dmarc.png
assets/images/kb-spf.png
assets/images/kb-email-auth.png
```
