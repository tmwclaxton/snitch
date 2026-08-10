---
paths:
  - app/Services/Apify/Adapters/YoutubeAdapter.php
  - app/Services/TikHub/Adapters/YoutubeAdapter.php
  - app/Services/Scraping/YoutubeMediaHydrator.php
---

# Adapters

## YouTube Shorts need TikHub MP4 hydration + public copy
List/sync may only have a Shorts page URL. Never store `youtube.com` / `youtu.be` page URLs as `media_url` (keep them on `url` for embeds). `YoutubeMediaHydrator` calls TikHub `get_video_info_v2`, picks a muxed progressive MP4 from `streamingData.formats` (fallback: adaptive video/mp4), then downloads it to `storage/app/public/youtube-media/{id}.mp4` and stores the public `/storage/...` URL. Googlevideo links are IP-bound and NanoGPT cannot fetch them directly. Both YouTube adapters run this in `hydrateMediaUrls`; `AnalyzePostJob` rehydrates page or googlevideo media before analysis. Without `TIKHUB_API_KEY`, unresolved Shorts are dropped from sync.

## Public-disk media probe and local APP_URL
`AnalyzePostJob::mediaLooksGone` must treat `/storage/...` URLs via `PublicDiskMedia` filesystem existence, not HTTP HEAD against `APP_URL`. A missing `public/storage` symlink makes Laravel return 403 for those URLs and was falsely marking hydrated Shorts unavailable. Keep `php artisan storage:link` in local setup (`composer setup`). When `APP_URL` is loopback, `VideoAnalysisService` inlines a `data:video/...;base64,...` URL so NanoGPT can still analyse the on-disk copy.

## YouTube Shorts posted_at from web_v2 metadata
TikHub `get_channel_short_videos` often returns empty `published_time`, and `get_video_info_v2` player payloads lack publish dates. When `posted_at` is null, `YoutubeMediaHydrator` calls `web_v2/get_video_info` and parses `publish_date` / `upload_date` / `date_text` (including `YYYY年M月D日`) / relative strings via `SocialDateParser`. Hydrate and `AnalyzePostJob` backfill existing nulls; mapPost also accepts those fields when present on list items.
