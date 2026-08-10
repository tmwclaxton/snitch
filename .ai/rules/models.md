---
paths:
  - app/Models/Post.php
  - app/Models/SocialAccount.php
  - app/Models/TrackedAccount.php
  - app/Services/SocialAccounts/**
---

# Models

## Global social corpus (not per-user post copies)
`social_accounts` is the global identity (platform + handle / external_id). `tracked_accounts` is the per-user membership ("I follow this social account") with kind + sync state. `posts` belong to `social_account_id` only - no `user_id` / `tracked_account_id` ownership. Unique `(social_account_id, external_id)`. Deleting a tracked account must not delete posts or analyses. Re-adding a handle resolves the same `SocialAccount` via `SocialAccountResolver` and immediately sees existing corpus posts. Feed/backlog/winners use `Post::forUser($user)` (membership join). Explore queries the shared completed-reel corpus. `AnalyzePostJob` / `EmbedPostAnalysisJob` take an optional `billingUserId` (sync initiator); analysis is shared once. Winners stay per-user (`winner_insights.user_id` + `post_id`).

## Post::youtubeMediaIsPageUrl guards YouTube page media
Use Post::youtubeMediaIsPageUrl() before NanoGPT analysis. When true, `AnalyzePostJob` must resolve a downloadable MP4 via `YoutubeMediaHydrator` (TikHub) before analyzing; only fail if hydration cannot produce a file URL.

## Analysis backlog scopes stay inside recency
`analysisQueue` / `analysisFailed` / `analysisBacklog` chain `withinAnalysisRecency` (`posted_at` null or >= now - `snitch.sync.recency_days`). Archive dates filled after YouTube hydrate must not appear as Waiting on `/backlog`.
