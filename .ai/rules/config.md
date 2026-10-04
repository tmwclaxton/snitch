---
paths:
  - config/snitch.php
---

# Config

## Sync and analyze stay inside the recency cost cap
Import only posts within the scrape window: weekly `snitch.sync.recency_days` (default 30) / `posts_limit` (12), or first-sync `first_sync_recency_days` (90) / `first_sync_posts_limit` (50). Analysis eligibility uses `SyncOptions::analysisRecencyDays()` = max of those windows so backfilled posts remain analyzable without widening every future scrape. Manual UI and MCP sync_competitor may override via posts_limit / recency_days (capped by posts_limit_max / recency_days_max). Do not scrape deep archives during vetting or probes. Documented via SNITCH_SYNC_* env vars.

## Tracked accounts sync on demand (min interval for ops)
snitch.sync.min_interval_days (default 7, SNITCH_SYNC_MIN_INTERVAL_DAYS) gates SyncTrackedAccountJob when force=false and the ops-only `snitch:sync-accounts` command. Do not schedule a full account sync for every user - agents/users trigger those so usage is intentional. `snitch:refresh-followers` is the weekly exception: current follower count only, for social accounts that still have a tracker. `snitch:daily-refresh` is the second exception, opt-in only (`users.daily_brief_enabled`), light posts_limit, no forced profile resolve. Manual UI/MCP sync always force-runs. Product UI shows Sync status (Manual / last synced / Syncing), not a next-auto-sync countdown. Live probes may force. Do not schedule aggressive daily Apify pulls for users who have not opted in.

## Per-platform Apify over-fetch multipliers
snitch.sync.fetch_multipliers (instagram 2.5, facebook 2, linkedin 2, tiktok 1.25, youtube 1) sizes raw actor results so reel-only mapping can still fill posts_limit. Prefer these over a blanket 3x.
