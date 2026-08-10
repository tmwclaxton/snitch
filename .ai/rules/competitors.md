---
paths:
  - 'app/Services/Competitors/**'
  - 'resources/js/pages/competitors/**'
  - 'app/Http/Controllers/CompetitorController.php'
---

# Competitors

## Sync is intentional - no auto-sync countdown
Competitors Index/Show show Sync status only: Manual, last synced date, or Syncing. Do not expose next_sync_at / sync_due or a scheduled countdown. Agents and users kick sync; snitch:sync-accounts is ops-only and not registered in routes/console.php.

## Suggest runs share one active cache pointer
Web and MCP must call `SuggestCompetitorsJob::beginRun()` before dispatch so `competitor-suggest-active:{userId}` is set. Competitors Index reads that pointer as `suggestRun` and polls until terminal. Do not seed only `latest` / status `queued` - the UI will miss in-progress agent jobs.

## Index table is reel-focused scan columns
Competitors Index counts reel-like posts only (`reels_count`), plus cheap `analysis_backlog_count` and `winners_count`. Do not show a separate Posts column or invent follower metrics not stored on TrackedAccount.

## Competitor suggest is Firecrawl-first
Discovery order: Firecrawl search -> NanoGPT normalize/dedupe grounded in hits -> Apify resolveProfile (require external_id). Do not invent rivals from LLM memory alone. Target 12-16 verified rows when possible; fail clearly under min_suggestions. Multi-platform mix including youtube.

## Suggest must not starve non-Facebook platforms
Run niche-led per-platform Firecrawl `site:` queries (instagram, tiktok, youtube, linkedin, facebook), not brand-name-only TikTok fishing. LinkedIn query covers company pages and `/in/` creators. Interleave candidates across platforms through merge and verify. Soft-cap any one platform (`max_per_platform`, default 3) while other platforms still have candidates; relax only to meet `min_suggestions`. Reject pure numeric Facebook handles (`@1000…`) unless Apify resolves a non-numeric vanity handle.

## LinkedIn verify uses company vs profile actors
Default Apify actor is `apimaestro/linkedin-company-posts` (`company_name`). Personal `/in/` resolves use `apimaestro/linkedin-profile-posts` (`username`). Pass full LinkedIn URLs into resolve so path kind is preserved. Profile `external_id` comes from `source_company` / `author.username` (live payloads have no `author.id`).

## MCP suggest loop must confirm
Agents must finish `suggest_competitors` → poll `suggest_competitors_status` → `confirm_competitor_suggestions` (selected handles) or `dismiss_competitor_suggestions`. Suggestions are cache/UI only until confirmed; they are NOT TrackedAccounts. Do not treat a completed suggest run as done. Keep this explicit in SnitchServer `#[Instructions]`, tool `#[Description]`s, and response `note` fields.

## Confirmed rivals leave the suggestion table
On confirm (web and MCP) and manual add, prune those platform+handle rows from `competitor-suggest-latest` instead of wiping the whole set. Index/page load also filters already-tracked accounts out of persisted suggestions. Dismiss still clears all; re-run still replaces the set.

## Suggest streams verified rows during processing
`SuggestCompetitorsJob` writes partial `suggestions` into the poll cache while status is `processing` (and keeps them on `failed` when under `min_suggestions`). Do not assume suggestions exist only on `completed`. Firecrawl searches and Apify resolves run via in-process `Http::pool` (`searchMany`, `resolve_concurrency`).

## Suggestion display_name prefers Apify profile names
On verify, set `display_name` from Apify `resolveProfile` (nickname / channel title) before Firecrawl hit titles or LLM strings. Strip platform suffixes (` - TikTok`, ` | TikTok`, ` on Instagram`). Reject TikTok/YouTube names that look like video or SEO titles (long, colon-heavy, tip/guide phrasing) and fall back to cleaned org name then handle. Confirmed TrackedAccount names copy suggestion `display_name`, so fix at suggest time.
