---
paths:
  - 'app/Services/SnitchAnalyticsService.php'
  - 'app/Support/AnalyticsDateRange.php'
  - 'app/Http/Controllers/AnalyticsController.php'
  - 'app/Http/Requests/AnalyticsPeriodRequest.php'
  - 'app/Console/Commands/BackfillAnalyticsCommand.php'
  - 'app/Models/SnitchDailyStat.php'
  - 'app/Models/SnitchDailyPlatformStat.php'
  - 'resources/js/pages/marketing/Analytics.vue'
  - 'resources/js/components/analytics/**'
---

# Public analytics

## Real aggregates only
`/analytics` and `/analytics.json` publish global counters only (posts synced, analyses completed, winners scored), plus period platform mix and top catalogue terms. Never synthetic vanity increments. Never per-user stats, handles, captions, brand names, or other PII.

## Write path
Increment via `SnitchAnalyticsService` from SyncTrackedAccountJob (new posts), VideoAnalysisService (completed + terms synced), and WinnerScorer (`wasRecentlyCreated` only). Rebuild history with `snitch:backfill-analytics`.
