---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Queue worker required for async sync and analyze
QUEUE_CONNECTION=database. SyncTrackedAccountJob and AnalyzePostJob are ShouldQueue; ConfirmSuggestions / UI sync need a running queue worker. Live probes may dispatchSync. Never assume sync/analyze finished because the HTTP request returned.

## Redis retry_after must exceed longest job timeout
Production uses redis queues. Keep `REDIS_QUEUE_RETRY_AFTER` / config `queue.connections.redis.retry_after` (default 660) greater than the longest `ShouldQueue` `$timeout` (`SuggestCompetitorsJob` is 600). If retry_after is shorter, Redis releases in-flight jobs and workers hit `MaxAttemptsExceeded` / duplicate `failed_jobs` uuid inserts.

## SuggestCompetitorsJob needs longer attempts
Firecrawl + Apify verify can exceed a short worker window, and deploys can SIGTERM mid-run. Keep `$tries` at least 3 with backoff (15s/60s) and a 600s timeout so MaxAttemptsExceeded is not the first failure mode after a restart.

## SyncTrackedAccountJob respects weekly min interval for ops/force gates
Unless force=true, the job no-ops when TrackedAccount::isDueForSync() is false (successful sync within snitch.sync.min_interval_days). Manual UI and MCP sync always dispatch with force=true. Do not register snitch:sync-accounts on the scheduler - agents/users kick sync intentionally. The artisan command remains for ops only and still filters by isDueForSync(). Product UI shows Sync status (Manual / last synced date / Syncing), never a next-auto-sync countdown.

## Sync is resolve-sparing and new-posts-only
Skip resolveProfile unless force or profile fields are incomplete. Import only new external_ids; soft-retry Failed analysis for known posts without re-scraping. TikTok hydrateMediaUrls (paid download) runs only for new candidates.

## Sync status running while queued or in flight
Mark TrackedAccount last_sync_status=running (and clear last_sync_error) only after the billing gate passes (balance above `billing.min_run_balance_pence`) when enqueueing SyncTrackedAccountJob from UI, confirm, store, MCP sync, or ops snitch:sync-accounts. Never mark running then fail the gate later - that makes Sync look in progress when blocked. The job also marks running after the due check, then success/failed when finished. Competitors Index/Show should treat running as an active sync (disable Sync, show Syncing, poll until terminal) and disable Sync when `subscription.can_run_billable` is false. Running accounts are not due for another ops sync.
