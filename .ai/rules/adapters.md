---
paths:
  - app/Services/Apify/Adapters/YoutubeAdapter.php
---

# Adapters

## YouTube Shorts analysis needs downloadable MP4
streamers/youtube-scraper often returns Shorts page URLs, not file URLs. Sync/embed can use the page URL; NanoGPT video analysis needs a downloadable MP4/webm. Do not treat youtube.com/shorts/... as analyzable media until a download URL exists. Known open gap from Phase B.
