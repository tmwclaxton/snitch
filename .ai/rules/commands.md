---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Live probes respect product assumptions and live flags
snitch:probe-e2e / probe-apify / probe-tikhub / probe-analysis-matrix gate on SNITCH_LIVE_* flags. They assert reel-only + recency caps, persist analysis via analyzePost when live, and treat WinnerInsight as optional. YouTube page media_url is a documented analysis gap, not a silent pass as completed analysis. `snitch:probe-tikhub` prints per-call COGS floors for Nike-style handles.

## Daily data audit is read-only
`snitch:audit --json [--user=ID]` never writes. Exit 1 if any check is `fail` (warnings are allowed). `sync_freshness`, `follower_snapshot_freshness`, and `trackers_zero_posts` treat trackers the daily sync would skip for low balance (`UsageBillingService::canRun` false) or over quota as `warn` with `skip_reason`, not `fail`. `follower_snapshot_freshness` also fails when today's row is not `source=profile` (copied from the tracker cache). Real collection problems (due, in quota, can run, still stale/failed/never synced, or copied) stay `fail`. `snitch:refresh-followers` runs inline at 06:00 UTC and again at 10:00 UTC; it returns FAILURE when every or most fetches fail so `scheduled_jobs` can flag the day. `--force` re-fetches even when a profile snapshot exists today and updates that row. `--queue` is ops-only. After deploy, confirm `schedule:list` shows `0 6 * * *` and `0 10 * * *` refresh-followers, `15 6 * * *` sync-accounts, and `25 6 * * *` generate-daily-briefs.
