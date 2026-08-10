---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Live probes respect product assumptions and live flags
snitch:probe-e2e / probe-apify / probe-tikhub / probe-analysis-matrix gate on SNITCH_LIVE_* flags. They assert reel-only + recency caps, persist analysis via analyzePost when live, and treat WinnerInsight as optional. YouTube page media_url is a documented analysis gap, not a silent pass as completed analysis. `snitch:probe-tikhub` prints per-call COGS floors for Nike-style handles.
