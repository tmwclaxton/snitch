---
paths:
  - 'app/Services/Apify/**'
---

# Apify

## Product scope is reel and short-video only
Skip images, carousels, text-only, and items without a resolvable video media_url on sync. Prefer PostType::Reel for short video. YouTube imports Shorts only (skip long-form). Feed/analysis/winners operate on reel-like types only.

## posts.media_url is text, not varchar(255)
Facebook/TikTok CDN video URLs regularly exceed 255 chars. Keep `posts.media_url` as `text` (same reason `tracked_accounts.avatar` is text).

## LinkedIn actors are company vs profile
Default `snitch.apify.actors.linkedin` is `apimaestro/linkedin-company-posts` (`company_name`). Personal `/in/` resolves use `linkedin_profile` (`apimaestro/linkedin-profile-posts`, `username`). Do not send a `urls` array - that input is invalid for both actors.

## Sync skips resolve when profile fields are present
SyncTrackedAccountJob calls resolveProfile only when force=true or external_id / url / display_name is blank. Do not pay for a profile actor run on every weekly sync.

## New posts only - no Apify metric refresh
Known external_ids are not updateOrCreate'd. Soft-retry Failed analysis for existing posts with media in-process (no Apify). Do not re-scrape metrics for posts already imported.

## TikTok is metadata-first then paid download
TikTok listRecentPosts sets shouldDownloadVideos=false. hydrateMediaUrls runs a second actor call with postURLs + shouldDownloadVideos=true only for new analysis candidates missing media_url. Do not download videos for the full profile list.

## Platform fetch multipliers replace blanket 3x
snitch.sync.fetch_multipliers controls over-fetch (instagram 2.5, facebook/linkedin 2, tiktok 1.25, youtube 1). Do not hard-code limit*3 in adapters.
