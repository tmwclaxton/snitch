---
paths:
  - app/Services/Apify/Adapters/YoutubeAdapter.php
  - app/Services/TikHub/Adapters/YoutubeAdapter.php
  - app/Services/Scraping/YoutubeMediaHydrator.php
---

# Adapters

## YouTube Shorts need TikHub MP4 hydration
List/sync may only have a Shorts page URL. Never store `youtube.com` / `youtu.be` page URLs as `media_url` (keep them on `url` for embeds). `YoutubeMediaHydrator` calls TikHub `get_video_info_v2` and picks a muxed progressive MP4 from `streamingData.formats` (fallback: adaptive video/mp4). Both Apify and TikHub YouTube adapters run this in `hydrateMediaUrls`. `AnalyzePostJob` also hydrates before failing so older page-URL rows can recover. Without `TIKHUB_API_KEY`, unresolved Shorts are dropped from sync (not imported with a doomed page media_url).
