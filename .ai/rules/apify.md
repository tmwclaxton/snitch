---
paths:
  - 'app/Services/Apify/**'
  - 'app/Services/TikHub/**'
  - 'app/Services/Scraping/**'
---

# Apify (+ TikHub fallback)

## TikHub after monthly Apify COGS cap
Soft cap: `SNITCH_APIFY_MONTHLY_CAP_USD` (default 49) sums platform-wide ledger `cogs_usd` where `vendor=apify` for the current UTC month via `ApifyMonthlyCapGate`. Cap `0` means soft-exhausted immediately (always prefer TikHub when it can serve); set this in local/prod `.env` for full TikHub swapover. Apify HTTP 402/quota failures mark a hard-exhaust cache (`snitch:apify_hard_exhausted:YYYY-MM`) for the rest of the UTC month - that is an ops/quota override, not the soft COGS path; clear with `ApifyMonthlyCapGate::clearHardExhausted()` when the temporary unblock is done and the soft cap is not actually hit.

Product rule - "If we have to use Apify then that overrides the cap.": `shouldUseTikHub($platform)` is true only when Apify is soft- or hard-exhausted AND TikHub can serve that platform AND `TIKHUB_API_KEY` is present. When TikHub cannot serve (no adapter, missing key, Facebook-only, etc.), keep Apify even if over the monthly COGS cap, cap is `0`, or hard-exhausted - the cap/swap must not block that work. When TikHub can serve, keep the soft-cap → TikHub routing. `PlatformAdapterManager` and sync jobs follow this gate; they never refuse a sync solely because Apify is exhausted. Never put the TikHub key in query strings or commit it. Probe costs with `snitch:probe-tikhub` (`SNITCH_LIVE_TIKHUB=1`).

## ApifyClient and TikHubClient are singletons
Both clients buffer run costs in-process for `VendorUsageCharger::chargePulled*`. Register them as singletons in `AppServiceProvider` (same pattern as TikHub). A non-shared `ApifyClient` makes adapters record costs on one instance while the charger pulls an empty buffer from another - Snitch billing then shows Apify £0 even when Apify Console has runs. `SyncTrackedAccountJob` always pulls both Apify and TikHub buffers after a sync (empty Apify→TikHub fallback and YouTube TikHub hydrate can leave costs on either).

## Empty Apify sync falls back to TikHub
`SyncTrackedAccountJob` retries `listRecentPosts` via TikHub when Apify returns `[]` and a TikHub adapter exists for that platform. Apify can finish with an empty dataset and `$0` usage without tripping the monthly cap - without this fallback, sync marks success and advances `last_synced_at` while backlog stays empty. Manual/`force` sync always uses the full `recency_days` window (not incremental `last_synced_at - 1 day`) so a prior empty scrape cannot hide real posts.

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

## YouTube Shorts hydrate via TikHub video_info_v2
YouTube adapters leave `media_url` null when only a Shorts page URL exists, then `YoutubeMediaHydrator` fills a googlevideo MP4 in hydrateMediaUrls (and AnalyzePostJob for legacy rows). Requires `TIKHUB_API_KEY`. Facebook/LinkedIn reject platform page hosts as media_url; Instagram drops items without a video file URL (no page fallback). Null `posted_at` is backfilled from `web_v2/get_video_info` (`date_text` etc.) during hydrate/analyze - channel shorts list dates are often blank.

## Platform fetch multipliers replace blanket 3x
snitch.sync.fetch_multipliers controls over-fetch (instagram 2.5, facebook/linkedin 2, tiktok 1.25, youtube 1). Do not hard-code limit*3 in adapters.

## runActors soft-fails every key, including singles
`ApifyClient::runActors` returns `[]` for a key on HTTP/connection failure (pool path and the single-job shortcut). Do not let one actor timeout (`ConnectionException`) abort competitor verify or other batch callers.
