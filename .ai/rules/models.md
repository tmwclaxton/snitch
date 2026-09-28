---
paths:
  - app/Models/Post.php
  - app/Models/SocialAccount.php
  - app/Models/TrackedAccount.php
  - app/Services/SocialAccounts/**
---

# Models

## Global social corpus (not per-user post copies)
`social_accounts` is the global identity (platform + handle / external_id). `tracked_accounts` is the per-user membership ("I follow this social account") with kind + sync state. `posts` belong to `social_account_id` only - no `user_id` / `tracked_account_id` ownership. Unique `(social_account_id, external_id)`. Tracked accounts use SoftDeletes (`deleted_at`); user/MCP remove calls `delete()` (soft). Soft-deleted memberships stay out of Eloquent queries and `Post::forUser`. Re-add via `TrackedAccount::updateOrRestore()` (`withTrashed()` + `restore()`) so the unique `(user_id, platform, handle)` key is not hit. Do not `forceDelete` in product paths. Global `social_accounts` + `posts` + analyses stay. Feed/backlog/winners use `Post::forUser($user)` (membership join). The feed proof sheet and `list_feed` also use `visibleOnFeed` so Failed / Unavailable analyses and unavailable media do not appear; those stay on `/backlog`. Explore queries the shared completed-reel corpus. `AnalyzePostJob` / `EmbedPostAnalysisJob` take an optional `billingUserId` (sync initiator); analysis is shared once. Winners stay per-user (`winner_insights.user_id` + `post_id`).

## Local image mirrors (covers + avatars)
CDN stills and avatars expire. Persist covers under `storage/app/public/post-covers/{id}.{ext}` (`PostCoverArchive` / `PostCoverHydrator`) and avatars under `avatars/{social_id}-{sha1}.{ext}` (`AvatarArchive` / `AvatarMirror`). Keep the remote in `posts.cover_source_url`, `social_accounts.avatar_source_url`, and `tracked_accounts.avatar_source_url` (additive columns). `Post::coverUrl` only emits durable displays (local `/storage/post-covers` or ytimg). Never overwrite an existing local avatar with a remote URL on download failure. A successful mirror (and `AvatarMirror::propagateToAllTrackers`) must update `social_accounts` plus every `tracked_accounts` row with that `social_account_id` (`avatar` + `avatar_source_url`); do not only update the tracker handed to `apply`. `snitch:mirror-images --avatars` treats any tracker whose avatar is not local as needing work even when the social is already mirrored, and copies the local path across with no network call.

## Post::youtubeMediaIsPageUrl guards YouTube page media
Use Post::youtubeMediaIsPageUrl() before NanoGPT analysis. When true, `AnalyzePostJob` must resolve a downloadable MP4 via `YoutubeMediaHydrator` (TikHub) before analyzing; only fail if hydration cannot produce a file URL.

## Analysis backlog scopes stay inside recency
`analysisQueue` / `analysisFailed` / `analysisBacklog` chain `withinAnalysisRecency` (`posted_at` null or >= now - `SyncOptions::analysisRecencyDays()`, which covers first-sync backfill). Archive dates filled after YouTube hydrate must not appear as Waiting on `/backlog`.
