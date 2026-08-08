---
paths:
  - config/snitch.php
---

# Config

## Sync and analyze stay inside the recency cost cap
Import and analyze only posts within snitch.sync.recency_days (default 30) with snitch.sync.posts_limit (default 12). Do not scrape deep archives during vetting or probes. Documented via SNITCH_SYNC_RECENCY_DAYS / SNITCH_SYNC_POSTS_LIMIT.

## Tracked accounts auto-sync about once a week
snitch.sync.min_interval_days (default 7, SNITCH_SYNC_MIN_INTERVAL_DAYS) gates scheduled snitch:sync-accounts. Failed syncs and never-synced accounts stay eligible for the schedule. Manual UI sync always force-runs; the UI countdown shows the next auto sync only. Live probes may force. Do not schedule aggressive daily Apify pulls.
