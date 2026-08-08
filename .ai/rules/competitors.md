---
paths:
  - 'app/Services/Competitors/**'
---

# Competitors

## Competitor suggest is Firecrawl-first
Discovery order: Firecrawl search -> NanoGPT normalize/dedupe grounded in hits -> Apify resolveProfile (require external_id). Do not invent rivals from LLM memory alone. Target 12-16 verified rows when possible; fail clearly under min_suggestions. Multi-platform mix including youtube.

## Suggest must not starve non-Facebook platforms
Run niche-led per-platform Firecrawl `site:` queries (instagram, tiktok, youtube, linkedin, facebook), not brand-name-only TikTok fishing. LinkedIn query covers company pages and `/in/` creators. Interleave candidates across platforms through merge and verify. Soft-cap any one platform (`max_per_platform`, default 3) while other platforms still have candidates; relax only to meet `min_suggestions`. Reject pure numeric Facebook handles (`@1000…`) unless Apify resolves a non-numeric vanity handle.

## LinkedIn verify uses company vs profile actors
Default Apify actor is `apimaestro/linkedin-company-posts` (`company_name`). Personal `/in/` resolves use `apimaestro/linkedin-profile-posts` (`username`). Pass full LinkedIn URLs into resolve so path kind is preserved. Profile `external_id` comes from `source_company` / `author.username` (live payloads have no `author.id`).

## Confirmed rivals leave the suggestion table
On confirm (and manual add), prune those platform+handle rows from `competitor-suggest-latest` instead of wiping the whole set. Index/page load also filters already-tracked accounts out of persisted suggestions. Dismiss still clears all; re-run still replaces the set.

## Suggest streams verified rows during processing
`SuggestCompetitorsJob` writes partial `suggestions` into the poll cache while status is `processing` (and keeps them on `failed` when under `min_suggestions`). Do not assume suggestions exist only on `completed`. Firecrawl searches and Apify resolves run via in-process `Http::pool` (`searchMany`, `resolve_concurrency`).

## Suggestion display_name prefers Apify profile names
On verify, set `display_name` from Apify `resolveProfile` (nickname / channel title) before Firecrawl hit titles or LLM strings. Strip platform suffixes (` - TikTok`, ` | TikTok`, ` on Instagram`). Reject TikTok/YouTube names that look like video or SEO titles (long, colon-heavy, tip/guide phrasing) and fall back to cleaned org name then handle. Confirmed TrackedAccount names copy suggestion `display_name`, so fix at suggest time.
