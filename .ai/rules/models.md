---
paths:
  - app/Models/Post.php
---

# Models

## Post::youtubeMediaIsPageUrl guards the YouTube MP4 gap
Use Post::youtubeMediaIsPageUrl() before NanoGPT analysis or soft-retries. Sync may keep the Shorts page URL for embeds; analysis must fail fast without calling NanoGPT until a downloadable MP4/webm exists.
