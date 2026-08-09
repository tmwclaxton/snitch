# Platform embeds (lazy + concurrency)

Instagram/TikTok official embed iframes rate-limit when many load at once. The in-iframe "Server error / Something is wrong on our end" copy is the platform's error page, not Snitch's.

## Rules

- `PlatformEmbed` defaults to `lazy: true` (IntersectionObserver, ~120px rootMargin).
- Feed/explore/backlog/competitor grids use `FeedContactCell` which must leave the default lazy on. Do not pass `:lazy="false"` on multi-embed lists (Dashboard winners strip, Winners page, contact sheets).
- Single-post detail (`feed/Show`) may use `:lazy="false"` for one immediate player.
- Concurrent iframe starts are capped by `resources/js/lib/embedLoadQueue.ts` (max 2). Do not raise that aggressively.
- Keep thumbnail/`media_url` fallback visible until the iframe fires `load`, so reserved frames stay filled while waiting on visibility or the queue.
- Contact-cell frame CSS (`aspect-ratio: 3/4`) plus embed `aspect` metadata must stay so lazy placeholders do not collapse layout.
