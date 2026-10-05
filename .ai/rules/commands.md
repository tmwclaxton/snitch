---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Live probes respect product assumptions and live flags
snitch:probe-e2e / probe-apify / probe-tikhub / probe-analysis-matrix gate on SNITCH_LIVE_* flags. They assert reel-only + recency caps, persist analysis via analyzePost when live, and treat WinnerInsight as optional. YouTube page media_url is a documented analysis gap, not a silent pass as completed analysis. `snitch:probe-tikhub` prints per-call COGS floors for Nike-style handles.

## Daily data audit is read-only
`snitch:audit --json [--user=ID]` never writes. Exit 1 if any check is `fail` (warnings are allowed). `sync_freshness` treats trackers the daily sync would skip for low balance (`UsageBillingService::canRun` false) or over quota as `warn` with `skip_reason`, not `fail`. Real collection problems (due, in quota, can run, still stale/failed/never synced) stay `fail`. Scheduler liveness is `ScheduleHeartbeat` in Redis (tick every 5 minutes plus success/failure markers on the morning data commands). After deploy, confirm `schedule:list` shows `0 6 * * *` refresh-followers, `15 6 * * *` sync-accounts, and `25 6 * * *` generate-daily-briefs.
