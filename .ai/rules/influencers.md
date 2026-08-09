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

## Firecrawl-first discovery
Order: Firecrawl search -> NanoGPT propose grounded in hits -> Apify resolveProfile (require `external_id`). Do not invent creators from LLM memory alone. Fail clearly under `min_suggestions` via `InsufficientInfluencerSuggestionsException` (partial rows still returned for the UI). Probe locally with `php artisan snitch:probe-influencer-find --sync`.

## Single platform only
Search uses one platform (`platform` request field / `filters.platforms` length 1). Do not restore multi-select. Queries and the propose prompt must bias hard to that platform (`site:` / platform-named queries). Soft-cap `max_per_platform` still exists for safety but rarely matters on a single platform.

## Separate influencer quota
Influencer Keep creates `TrackedAccount` with `kind=influencer`. Competitor slots are separate (`kind=competitor`). Gate Keep with `PlanEntitlementService::canAddInfluencers`, not competitor create policy. Competitors index/show must list only `->competitors()`. Do not let Keep consume competitor quota.

## Keep / discard lock
While undecided suggestions remain for the active run, block a new search. Keep persists the profile and starts sync; Discard records the decision. Both update the poll cache so the UI can clear the card.

## Quality-loop lessons (prompts / queries)
- Prefer niche-led queries (`sneaker streetwear`, `cafe coffee food`, `belleza maquillaje`, `home workout fitness`, `startup grants`) over brand-name-only fishing.
- Add per-platform query variants (`site:instagram.com`, `site:tiktok.com/@`, LinkedIn `/in`, YouTube `@` / Shorts) plus micro/mid-tier wording when a follower band is set.
- Reject brands, tools, cosmetics labels, incubators, foundations, and regional org chapters on candidate name and again on resolved `display_name` (Apify often reveals org titles only after resolve).
- Instagram post scrapes usually lack follower counts; unknown followers are allowed, known counts must respect the band. TikTok/YouTube often return usable counts.
- Spanish / non-English briefs need language in Firecrawl queries and niche tokens (`belleza`, `maquillaje`) in topic extraction.
- Live probe scenarios that should stay solid: sneaker DTC Instagram, B2B grants LinkedIn, local cafe Instagram, Spanish beauty Instagram, fitness home-workout TikTok.
