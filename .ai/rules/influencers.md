---
paths:
  - 'app/Services/Influencers/**'
  - 'app/Http/Controllers/InfluencerController.php'
  - 'app/Http/Requests/Influencers/**'
  - 'app/Jobs/FindInfluencersJob.php'
  - 'app/Console/Commands/ProbeInfluencerFindCommand.php'
  - 'app/Enums/TrackedAccountKind.php'
  - 'resources/js/pages/influencers/**'
  - 'tests/Feature/InfluencersFindTest.php'
  - 'tests/Unit/Services/Influencers/**'
---

# Find influencers

## Multi-seed discovery
Order: NanoGPT model seed + Firecrawl search/propose + vendor native platform search (Apify, or TikHub when `ApifyMonthlyCapGate` is exhausted) -> merge/dedupe -> platform resolve (require `external_id`) with follower extraction. Do not invent Keep-ready creators from LLM memory alone; model seeds always go through resolve verify. Fail clearly under `min_suggestions` via `InsufficientInfluencerSuggestionsException` (partial rows still returned for the UI). Probe locally with `php artisan snitch:probe-influencer-find --sync`.

When Apify monthly COGS is exhausted and `TIKHUB_API_KEY` is set, `seedFromApifySearch` calls TikHub user/channel search for IG/TT/YT and labels seeds `tikhub-search`. Facebook has no TikHub search path and verify/resolve stays on Apify. Charge pulled TikHub runs on find jobs.

Config toggles: `snitch.influencer_find.seeds.{model,firecrawl,apify_search}` (default all true), `model_seed_count`, `apify_search_limit`.

## Merge / verify preferences
Dedupe by `platform:handle`. Prefer candidates that already have follower counts (usually Apify/TikHub search). Interleave seed sources (`apify-search`, `tikhub-search`, `firecrawl`, `model-seed`) so one source cannot starve the verify queue. Instagram resolve uses `resultsType: details` so `followersCount` is available for band filtering. Unknown followers remain allowed; known in-band counts are prioritized.

## Org / brand junk classification
Do not grow regex forests for brand/org/tool rejection. Batch candidates through NanoGPT JSON (`rejectOrgOrBrandKeys` / `filterCreatorCandidates`) on the cheap `influencer_find` model. Fail soft on bad JSON (keep candidates). Tiny mechanical regex only (e.g. `@` strip, Facebook pure-numeric handles, reserved path segments).

## Single platform only
Search uses one platform (`platform` request field / `filters.platforms` length 1). Do not restore multi-select. Queries and the propose prompt must bias hard to that platform (`site:` / platform-named queries). Soft-cap `max_per_platform` still exists for safety but rarely matters on a single platform.

## Separate influencer quota
Influencer Keep creates `TrackedAccount` with `kind=influencer`. Competitor slots are separate (`kind=competitor`). Gate Keep with `PlanEntitlementService::canAddInfluencers`, not competitor create policy. Competitors index/show must list only `->competitors()`. Do not let Keep consume competitor quota.

## Keep / discard lock
While undecided suggestions remain for a completed run, block a new search. Failed runs (including thin partials under `min_suggestions`) may start a new search without finishing Keep/Discard. Keep persists the profile and starts sync; Discard records the decision. Both update the poll cache so the UI can clear the card. UI may hint: "Last search found fewer than 6 - you can keep reviewing or search again."

## Quality-loop lessons (prompts / queries)
- Prefer niche-led queries (`sneaker streetwear`, `cafe coffee food`, `belleza maquillaje`, `home workout fitness`, `startup grants`) over brand-name-only fishing.
- Add per-platform query variants (`site:instagram.com`, `site:tiktok.com/@`, LinkedIn `/in`, YouTube `@` / Shorts) plus micro/mid-tier wording when a follower band is set.
- Reject brands/orgs via NanoGPT batch classify on candidate name and again on resolved `display_name` (Apify often reveals org titles only after resolve).
- Instagram post scrapes usually lack follower counts; use profile `details` on resolve. TikTok/YouTube often return usable counts from search seeds.
- Spanish / non-English briefs need language in Firecrawl queries and niche tokens (`belleza`, `maquillaje`) in topic extraction.

## Live probe findings (multi-seed, 2026-08-09)
- **GrantGunner Instagram 1k-15k (sparse niche):** Firecrawl-only was ~1 verified. Multi-seed raised fill to **3-4** verified across runs (still under min 6). Typical seed mix among kept rows: apify-search + model-seed; Firecrawl often contributed 0 kept rows in this niche. Follower counts are now usually present and in-band thanks to Instagram `details` resolve + Apify search. Org junk (Seedcamp / Founders Factory) is filtered by batched NanoGPT reject (not regex). Remaining failures: sparse creator graph for grants on IG, Apify user-search niche noise (e.g. English-learning accounts), model handles that resolve to the wrong person.
- **Fashion TikTok 1k-50k:** **10/10** verified. Mix: firecrawl ~3, apify-search ~6, model-seed ~1. Followers present. Some aggregator/streetwear dump accounts from Apify search.
- **Sneaker Instagram regression:** **7** verified (>=6). Mix of all three seeds. Followers present. Quality gaps: off-niche model seeds and Apify search noise.
- **Fitness TikTok regression:** **9** verified. Mostly firecrawl + some apify-search. Followers present. Occasional off-niche keeps.

### Verdict
Multi-seed is **better for fill rate and follower-band enforcement**, especially TikTok / denser niches. For **GrantGunner-class sparse Instagram niches it is improved but not fixed** (best ~4 < 6). Remaining gaps: niche relevance of Apify user search + model memory, and sparse public creator inventory for grants/fundraising on Instagram.
