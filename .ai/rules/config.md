---
paths:
  - config/snitch.php
  - app/Services/Chat/**
  - app/Http/Controllers/ChatController.php
  - resources/js/pages/chat/**
  - routes/web.php
---

# Config

## In-app chat uses stored MCP reads and a daily cap
`/chat` calls the same stored-data tools as MCP (`ChatToolRunner` / `McpReadService`) under the logged-in user. Never trigger Apify/TikHub from chat. Cap with `snitch.chat.daily_message_cap` (`SNITCH_CHAT_DAILY_CAP`, Europe/London day). When capped, stream a plain message plus Connect your AI (`/settings/mcp`). Model: `snitch.chat.model`.

## Sync and analyze stay inside the recency cost cap
Import only posts within the scrape window: weekly `snitch.sync.recency_days` (default 30) / `posts_limit` (12), or first-sync `first_sync_recency_days` (90) / `first_sync_posts_limit` (50). Analysis eligibility uses `SyncOptions::analysisRecencyDays()` = max of those windows so backfilled posts remain analyzable without widening every future scrape. Manual UI and MCP sync_competitor may override via posts_limit / recency_days (capped by posts_limit_max / recency_days_max). Do not scrape deep archives during vetting or probes. Documented via SNITCH_SYNC_* env vars.

## Tracked accounts sync on demand (min interval for ops)
snitch.sync.min_interval_days (default 7, SNITCH_SYNC_MIN_INTERVAL_DAYS) gates SyncTrackedAccountJob when force=false. Daily `snitch:sync-accounts` (06:15 UTC) still uses that gate for users who have not opted in, so Apify spend stays one scrape per interval. `snitch:refresh-followers` is the daily calendar-day exception (06:00 UTC plus 10:00 UTC retry, interval default 1): current follower count only, for social accounts that still have a tracker and no `source=profile` snapshot today. TikHub Instagram `user_info` is v2 `fetch_user_info` with a v1 `fetch_user_info_by_username_v2` fallback. The other exception is opt-in daily-brief light sync on the same `snitch:sync-accounts` run (`users.daily_brief_enabled`, small posts_limit, no forced profile resolve). Do not also schedule `snitch:daily-refresh`. Manual UI/MCP sync always force-runs. Product UI shows Sync status (Manual / last synced / Syncing), not a next-auto-sync countdown. Live probes may force. Do not schedule aggressive daily Apify pulls for users who have not opted in.

## Per-platform Apify over-fetch multipliers
snitch.sync.fetch_multipliers (instagram 2.5, facebook 2, linkedin 2, tiktok 1.25, youtube 1) sizes raw actor results so reel-only mapping can still fill posts_limit. Prefer these over a blanket 3x.
