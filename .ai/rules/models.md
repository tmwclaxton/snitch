---
paths:
  - app/Models/Post.php
---

# Models

## Post::youtubeMediaIsPageUrl guards YouTube page media
Use Post::youtubeMediaIsPageUrl() before NanoGPT analysis. When true, `AnalyzePostJob` must resolve a downloadable MP4 via `YoutubeMediaHydrator` (TikHub) before analyzing; only fail if hydration cannot produce a file URL.

## Analysis backlog scopes stay inside recency
`analysisQueue` / `analysisFailed` / `analysisBacklog` chain `withinAnalysisRecency` (`posted_at` null or >= now - `snitch.sync.recency_days`). Archive dates filled after YouTube hydrate must not appear as Waiting on `/backlog`.
