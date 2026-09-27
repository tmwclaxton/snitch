---
paths:
  - 'app/Services/Competitors/**'
  - 'resources/js/pages/competitors/**'
  - 'app/Http/Controllers/CompetitorController.php'
---

# Snitches (UI name for tracked competitor accounts)

The product page is **Tracking** at `/tracking` (sidebar and page title). `/snitches` and `/competitors` 301 there. Named routes, MCP tool names, `kind=competitor`, and PHP classes stay `competitors` / `Competitor*` for stability.

## Untrack soft-deletes membership, not the corpus
Removing a snitch/influencer soft-deletes the user's `tracked_accounts` row (`SoftDeletes` / `deleted_at`). Global `social_accounts` + `posts` + analyses stay. Re-add must use `TrackedAccount::updateOrRestore()` so a trashed row is restored instead of colliding with unique `(user_id, platform, handle)`. Sync refreshes existing posts; it should not recreate from zero when the reel already exists.

## Weekly refresh is scheduled
Lovable core refreshes tracked Instagram accounts weekly via `snitch:sync-accounts` in `routes/console.php` (Monday 07:00). Competitors Index shows last refresh from `last_synced_at`. Do not expose `next_sync_at` / `sync_due` or a countdown. Users can still kick a manual sync.

## Suggest runs share one active cache pointer
Web and MCP must call `SuggestCompetitorsJob::beginRun()` before dispatch so `competitor-suggest-active:{userId}` is set. Snitches Index reads that pointer as `suggestRun` and polls until terminal. Do not seed only `latest` / status `queued` - the UI will miss in-progress agent jobs.

## Dashboard reuses backup cadence UI inside the dense compare shell
`DashboardMetrics` owns `/dashboard`: Instagram-only own vs selected rivals, period control, CardResult cards, and a one-row `rail` with you + peer median hints. Cadence uses `DashboardActivityBuilder::forSocialAccounts` scoped to the selected Instagram social IDs (shared 16-week heatmap / 12-week weekly + time-of-day window). Caption chips, CTA language, and corpus format mix come from `CompetitorInsightsBuilder::captionIntel` on that same scoped period - do not call `forUser`/`growth()` on the dashboard path (those seed follower snapshots on GET). Follower history is read-only via `followerSeriesForIds` from existing `follower_snapshots` (written on sync / weekly refresh only). First paint keeps controls + rail (+ kpis/onboarding); defer `activity`, `follower_series`, `recent_posts`, `caption_intel`, winners, and the other CardResult boards together in Inertia group `panel`. Port backup components (`PostingHeatmap`, `FollowerHistoryChart`, `FormatMixChart`, `TimeOfDayChart`, `WeeklyVolumeChart`, `FeedContactCell`, `CtaLanguage`) and restyle under `.snitch-app-chrome` - no paper/tape/grain/tilt, no platform-split chart, no live iframes in tiles, no `?frames=` resize refetch (fixed 24 covers clipped to two rows). Compact winner rows link to `feed.show` and `competitors.show`. Per-snitch Show still uses `CompetitorInsightsBuilder::forAccount`. Hashtags/keywords stay glance chips (TERM_LIMIT 12), not tall word lists. CTA chips expand to verbatim lines linked to source posts. Rail is 9 cells: 3 columns below xl, one 9-column row at xl via `:has(> :nth-child(9))`. Surface ER / n / lift numbers on format lift and insights - do not show labels alone. Show labels account ER as "Engagement / view"; dashboard follower-based ER is "Engagement rate" / "Avg engagement / follower". The full Active ads catalogue stays at `/ad-library`.

## Caption keywords skip filler
`CompetitorInsightsBuilder` keywords are topic-ish tokens (min 4 letters) after a stopword list (`should`, `these`, `today`, `start`, …). Do not rank "the / should / say". Hashtags stay raw `#tags`. Show them as wrapping glance chips, not a tall count list.

## Index table counts all importable posts
Snitches Index shows `posts_count` (reels, videos, images, carousels). Keep `reels_count` for analysis backlog. Analysis covers reels plus stills. Follower growth uses `follower_snapshots`, not a guessed rate. A weekly profile refresh records the current count while someone still tracks the account. Follower counts are shared across every tracker of the same social account - a newly added tracker inherits the latest known snapshot/tracker count even when refresh is skipped for a recent snapshot. The dashboard and account page chart that series. A missing earlier snapshot still leaves week_delta null instead of treating the whole follower count as growth.

## Bulk select floating bar
Suggested snitches and tracked snitches use independent checkbox selection. Shared `BulkActionBar` scrap floats with Confirm/Dismiss (suggestions) or Sync/Remove (tracked). Batch routes: `competitors.batch-sync`, `competitors.batch-destroy`. Prefer the shared component over a second inline scrap bar.

## Snitch suggest is Firecrawl-first
Discovery order: Firecrawl search -> NanoGPT normalize/dedupe grounded in hits -> Apify resolveProfile (require external_id). Do not invent rivals from LLM memory alone. Target 12-16 verified rows when possible; fail clearly under min_suggestions. Multi-platform mix including youtube.

## Suggest is niche-first (not brand-name-first)
Lead Firecrawl with niche phrase + website host queries. Weak / ambiguous brand names (short single tokens or slang like "Snitch") must not drive `"{$name} competitors"` searches. Niche comes from suggest `brief`, else `brand_profiles.competitor_brief`, else brand description - empty niche blocks suggest (MCP BrandContext + service gate). Agents may pass `brief` on `suggest_competitors` or set description via update_brand / start_brand_autofill. Propose prompt rejects name-collision junk (fashion/meme/homonyms) when the brand is SaaS/software.

## Suggest options modal (web) + filters (MCP)
Snitches Index opens a modal before kickoff (space is tight for an inline form). Required: one or more platforms + brief (min 8). Optional Generate drafts `competitor_brief` via NanoGPT (`competitor.brief`). Web POST `/tracking/suggest` body `{ platforms, brief }`; MCP `suggest_competitors` accepts optional `platforms` + `brief` (omit = config platforms + brand niche). Job stores `filters` in the suggest cache payload and passes them into `CompetitorSuggestionService`. Read filters via a safe helper (`resolvedFilters`) - jobs queued before the `filters` constructor arg can unserialize with that typed property uninitialized, and raw `$this->filters` crashes `putStatus` / `failed`.

## Suggest must not starve non-Facebook platforms
Run niche-led per-platform Firecrawl `site:` queries (instagram, tiktok, youtube, linkedin, facebook), not brand-name-only TikTok fishing. LinkedIn query covers company pages and `/in/` creators. Interleave candidates across platforms through merge and verify. Soft-cap any one platform (`max_per_platform`, default 3) while other platforms still have candidates; relax only to meet `min_suggestions`. Reject pure numeric Facebook handles (`@1000…`) unless Apify resolves a non-numeric vanity handle.

## LinkedIn verify uses company vs profile actors
Default Apify actor is `apimaestro/linkedin-company-posts` (`company_name`). Personal `/in/` resolves use `apimaestro/linkedin-profile-posts` (`username`). Pass full LinkedIn URLs into resolve so path kind is preserved. Profile `external_id` comes from `source_company` / `author.username` (live payloads have no `author.id`).

## MCP suggest loop must confirm
Agents must finish `suggest_competitors` → poll `suggest_competitors_status` → `confirm_competitor_suggestions` (selected handles) or `dismiss_competitor_suggestions`. Suggestions are cache/UI only until confirmed; they are NOT TrackedAccounts. Do not treat a completed suggest run as done. Keep this explicit in SnitchServer `#[Instructions]`, tool `#[Description]`s, and response `note` fields. MCP `handles` accepts plain strings (matches every platform for that handle) or `{platform, handle}` objects for a single platform row. Confirm only after `status=completed`; mid-run confirms are rejected unless MCP/web pass `allow_partial=true`. Reject weak/generic handles (e.g. `content`) on confirm and manual add via `SocialHandle`.

## Confirmed rivals leave the suggestion table
On confirm (web and MCP) and manual add, prune those platform+handle rows from the suggest run instead of wiping the whole set. MCP confirm must call `SuggestCompetitorsJob::pruneSuggestions($userId, $suggestId, …)` (not only `pruneLatestSuggestions`) so prune still works if the latest pointer is missing. Index/page load also filters already-tracked accounts out of persisted suggestions. Dismiss / `clearRun` clears payload + latest + active; MCP `confirm_competitor_suggestions` defaults `dismiss_remainder=true` so leftover pending cards clear after a typical confirm - pass `false` to keep remainder. Re-run still replaces the set.

## Suggest streams verified rows during processing
`SuggestCompetitorsJob` writes partial `suggestions` into the poll cache while status is `processing` (and keeps them on `failed` when under `min_suggestions`). Do not assume suggestions exist only on `completed`. Firecrawl searches and Apify resolves run via in-process `Http::pool` (`searchMany`, `resolve_concurrency`).

## Suggestion display_name prefers Apify profile names
On verify, set `display_name` from Apify `resolveProfile` (nickname / channel title) before Firecrawl hit titles or LLM strings. Strip platform suffixes (` - TikTok`, ` | TikTok`, ` on Instagram`). Reject TikTok/YouTube names that look like video or SEO titles (long, colon-heavy, tip/guide phrasing) and fall back to cleaned org name then handle. Confirmed TrackedAccount names copy suggestion `display_name`, so fix at suggest time.
