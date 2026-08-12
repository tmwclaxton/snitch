---
paths:
  - config/snitch.php
---

# Config

## Sync and analyze stay inside the recency cost cap
Import and analyze only posts within snitch.sync.recency_days (default 30) with snitch.sync.posts_limit (default 12). Manual UI and MCP sync_competitor may override via posts_limit / recency_days (capped by posts_limit_max default 50, recency_days_max default 90). Do not scrape deep archives during vetting or probes. Documented via SNITCH_SYNC_* env vars.

## Tracked accounts sync on demand (min interval for ops)
snitch.sync.min_interval_days (default 7, SNITCH_SYNC_MIN_INTERVAL_DAYS) gates SyncTrackedAccountJob when force=false and the ops-only `snitch:sync-accounts` command. Do not schedule account sync for users - agents/users trigger sync so usage is intentional (`routes/console.php` must not register snitch:sync-accounts). Manual UI/MCP sync always force-runs. Product UI shows Sync status (Manual / last synced / Syncing), not a next-auto-sync countdown. Live probes may force. Do not schedule aggressive daily Apify pulls.

## Per-platform Apify over-fetch multipliers
snitch.sync.fetch_multipliers (instagram 2.5, facebook 2, linkedin 2, tiktok 1.25, youtube 1) sizes raw actor results so reel-only mapping can still fill posts_limit. Prefer these over a blanket 3x.
