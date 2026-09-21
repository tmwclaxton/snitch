# Platform covers (not official embeds)

Official Instagram/TikTok/Facebook iframes fight the print frames and rate-limit in grids. Do not load those players in product UI.

## Rules

- `PlatformEmbed` shows a still cover (`cover_url` from `PostCover`) and falls back to image `media_url`, then a muted video first frame.
- Do not add `<iframe>` players back to `PlatformEmbed`, `FeedContactCell`, Dashboard winners, Winners, or `feed/Show`.
- `Post` appends `cover_url`. Resolve covers from payload stills (displayUrl, originCover, thumbnails) or YouTube `hqdefault`. Never prefer a video file over a still.
- Keep thumbnail/`media_url` fallback visible so 3/4 contact frames and polaroids stay filled.
- `embedLoadQueue.ts` stays for any leftover iframe experiments. Do not wire it back into list or detail views.
- Contact-cell frame CSS (`aspect-ratio: 3/4`) must stay so covers do not collapse layout.
