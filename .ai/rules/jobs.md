---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Queue worker required for async sync and analyze
QUEUE_CONNECTION=database. SyncTrackedAccountJob and AnalyzePostJob are ShouldQueue; ConfirmSuggestions / UI sync need a running queue worker. Live probes may dispatchSync. Never assume sync/analyze finished because the HTTP request returned.

## SyncTrackedAccountJob respects weekly min interval for schedule
Unless force=true, the job no-ops when TrackedAccount::isDueForSync() is false (successful sync within snitch.sync.min_interval_days). Scheduled snitch:sync-accounts filters due accounts. Manual UI sync always dispatches with force=true; the next-sync countdown is informational for auto sync only.

## Sync is resolve-sparing and new-posts-only
Skip resolveProfile unless force or profile fields are incomplete. Import only new external_ids; soft-retry Failed analysis for known posts without re-scraping. TikTok hydrateMediaUrls (paid download) runs only for new candidates.

## Sync status running while queued or in flight
Mark TrackedAccount last_sync_status=running (and clear last_sync_error) when enqueueing SyncTrackedAccountJob from UI, confirm, store, or snitch:sync-accounts. The job also marks running after the due check, then success/failed when finished. Competitors Index/Show should treat running as an active sync (disable Sync, show Syncing, poll until terminal). Running accounts are not due for another scheduled sync.
