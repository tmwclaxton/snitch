---
paths:
  - app/Services/Apify/Adapters/YoutubeAdapter.php
  - app/Services/TikHub/Adapters/YoutubeAdapter.php
  - app/Services/Scraping/YoutubeMediaHydrator.php
---

# Adapters

## YouTube Shorts need TikHub MP4 hydration + public copy
List/sync may only have a Shorts page URL. Never store `youtube.com` / `youtu.be` page URLs as `media_url` (keep them on `url` for embeds). `YoutubeMediaHydrator` calls TikHub `get_video_info_v2`, picks a muxed progressive MP4 from `streamingData.formats` (fallback: adaptive video/mp4), then downloads it to `storage/app/public/youtube-media/{id}.mp4` and stores the public `/storage/...` URL. Googlevideo links are IP-bound and NanoGPT cannot fetch them directly. Both YouTube adapters run this in `hydrateMediaUrls`; `AnalyzePostJob` rehydrates page or googlevideo media before analysis. Without `TIKHUB_API_KEY`, unresolved Shorts are dropped from sync.
