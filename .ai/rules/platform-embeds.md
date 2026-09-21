# Platform covers (not official embeds)

Official Instagram/TikTok/Facebook iframes fight the print frames and rate-limit in grids. Do not load those players in product UI.

## Rules

- `PlatformEmbed` shows a still cover (`cover_url` from `PostCover`) and falls back to image `media_url`, then a muted video first frame.
- Do not add `<iframe>` players back to `PlatformEmbed`, `FeedContactCell`, Dashboard winners, Winners, or `feed/Show`.
- `Post` appends `cover_url`. Prefer the stored `posts.cover_url` column, then payload stills (displayUrl, TikTok `video.cover.url_list`, originCover), then YouTube `hqdefault`. Never prefer a video file over a still.
- Backfill existing rows with `php artisan snitch:backfill-covers` (add `--fetch` for TikTok oEmbed). Sync persists a cover from the payload on create. Do not Firecrawl every post for og:image.
- Keep thumbnail/`media_url` fallback visible so 3/4 contact frames and polaroids stay filled.
- Never put `!aspect-auto` on `.snitch-polaroid-frame` around `PlatformEmbed`. The still is `position: absolute`, so a frame without 3/4 height collapses to zero.
- `embedLoadQueue.ts` stays for any leftover iframe experiments. Do not wire it back into list or detail views.
- Contact-cell frame CSS (`aspect-ratio: 3/4`) must stay so covers do not collapse layout.
- Instagram sync imports stills and carousels as well as reels. Feed and Snitch Show list every imported type. AnalyzePostJob stays reel/video only.
- Proof sheets use `.snitch-contact-sheet-proof` (`auto-fill` 8.5-10.75rem cards). Do not stretch feed / snitch frames with `grid-cols-*` / `1fr` on the wide canvas.
- Dashboard Latest frames and Explore use `.snitch-contact-sheet-proof-fill` (`auto-fit` + `1fr`, min 12rem) so the board spans the canvas instead of a left-aligned strip. Keep 3/4 frames.
- `FeedContactCell` and Winners tear rows use a stretched hit `Link` to the post show page. Only `Open on {platform}` and the `@handle` profile `Link` sit above it. Do not wrap remake markdown (or the polaroid) in a second `<a>`.
