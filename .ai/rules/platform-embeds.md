# Platform covers (not official embeds)

Official Instagram/TikTok/Facebook iframes fight the print frames and rate-limit in grids. Do not load those players in product UI.

## Rules

- `PlatformEmbed` shows a still cover (`cover_url` from `PostCover`) and falls back to image `media_url`, then a muted video first frame.
- Feed, Explore, Dashboard, Winners, and Snitch Show sheets stay covers. Do not put `<iframe>` players in those grids.
- The post page (`feed/Show`) is the exception: pass `interactive` so one official player loads in `.snitch-post-player` (wide enough to use, not the 3/4 polaroid crop). Instagram uses `/embed/` there so the carousel can be swiped. Keep the "Open on platform" control off that player.
- `Post` appends `cover_url`. Prefer the stored `posts.cover_url` column, then payload stills (displayUrl, TikTok `video.cover.url_list`, originCover, Facebook `media.0.thumbnail`), then YouTube `hqdefault`. Never prefer a video file over a still.
- Instagram, TikTok, and Facebook CDN stills are signed and expire (Instagram `oe`, TikTok `x-expires`). `PostCoverHydrator` downloads those bytes to `storage/app/public/post-covers/{id}` and stores `/storage/post-covers/{id}.jpg`. YouTube `i.ytimg.com` stays a remote URL. Do not keep a signed CDN URL once the file is on disk.
- If the signed still 403s, `--fetch` (and sync) recovers a fresh image without Firecrawl: Instagram `/{p|reel}/{code}/media/?size=l`, Facebook page `og:image`, TikTok oEmbed. Do not Firecrawl every post for og:image.
- Backfill existing rows with `php artisan snitch:backfill-covers --fetch`. The command also rewrites stored `http` covers that are not `ytimg.com`. Sync archives a cover on create, and again for an existing post when the stored cover is not already a local file or a YouTube thumb.
- Keep thumbnail/`media_url` fallback visible so 3/4 contact frames and polaroids stay filled.
- Never put `!aspect-auto` on `.snitch-polaroid-frame` around `PlatformEmbed`. The still is `position: absolute`, so a frame without 3/4 height collapses to zero.
- `embedLoadQueue.ts` stays for any leftover iframe experiments. Do not wire it back into list views. The post page loads its single player directly.
- Contact-cell frame CSS (`aspect-ratio: 3/4`) must stay so covers do not collapse layout.
- Instagram sync imports stills and carousels as well as reels. Feed and Snitch Show list every imported type. AnalyzePostJob stays reel/video only.
- Proof sheets use `.snitch-contact-sheet-proof` (`auto-fill` 8.5-10.75rem cards) unless the board should span the canvas.
- Dashboard Latest frames, Feed, and Explore use `.snitch-contact-sheet-proof-fill` (`auto-fit` + `1fr`, min 12rem) so leftover canvas is not a right gutter. Keep 3/4 frames. Do not add `grid-cols-*` on those boards. Latest frames loads `frames` posts (default 6, clamped 1-24): two full rows of however many columns the sheet has, and the dashboard replaces that query as the width changes.
- Snitch Show recent posts use `.snitch-contact-sheet-rows` (`--snitch-sheet-cols` = ceil(count / 2), capped at 6) so the sheet aims for two rows and the frames fill the canvas.
- `FeedContactCell` and Winners tear rows use a stretched hit `Link` to the post show page. Only `Open on {platform}` and the `@handle` profile `Link` sit above it. Do not wrap remake markdown (or the polaroid) in a second `<a>`.
