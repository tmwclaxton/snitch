---
paths:
  - 'app/Services/Analysis/**'
  - 'database/data/analysis_terms.php'
  - 'app/Http/Controllers/ExploreController.php'
  - 'resources/js/pages/explore/**'
---

# Analysis

## Analysis is concept-first not transcript dump
Persist concept, topics, how_to_copy, hook, idea, visual, music/SFX, and CTA as its own field. Evaluator rejects caption echo and vague filler. Prompts forbid long script regurgitation and invented SFX. `how_to_copy` must be a Markdown numbered list with a newline per step (never inline "1. … 2. …"); `SafeMarkdown` / client markdown also normalize inline numbered/bullet runs so existing rows still render as real list items. Feed show renders CTA as its own sticker, not under How to remake.

## Hook window floors to the success minimum
Models often return `hook_window.end_sec` under 3s. `VideoAnalysisResult::fromModelPayload` floors to `snitch.video_analysis.success.min_hook_window_end_seconds` (default 3) before evaluate/persist so short opens do not burn AnalyzePostJob retries.

## Empty CTA floors to a sentinel
`require_cta_field` is true, but many posts have no ask. `fromModelPayload` floors blank `cta` to `No explicit CTA` (prompt asks for the same). Do not tell the model to return an empty string. Checklist failures already mark the analysis Failed; `AnalyzePostJob` must not rethrow those for another retry. Rows that failed with `cta missing` before this floor need a soft requeue (`AnalyzePostJob` / next sync) - the error is stale, not a live evaluator reject of the sentinel.

## Analysis output is English only
Prompts require UK English for every JSON string (concept, idea, topics, how_to_copy, visual, cta, labels). Spoken-word quotes in hook may keep the source language. Evaluator rejects Han/CJK prose so Qwen and similar models cannot persist Chinese analysis fields.

## Taxonomy slugs + custom_tags
Prompt includes the controlled catalogue from database/data/analysis_terms.php (~250 hook_type / topic / visual_craft slugs). Persist matched terms on the analysis_term pivot; store genuine misses in post_analyses.custom_tags. Mirror catalogue labels into topics for glance UI. Unknown slugs are dropped. Missing taxonomy alone must not fail the evaluator. When the model omits slugs but freeform hook/concept/topics clearly name a catalogue term (e.g. "myth-busting" -> myth_bust), AnalysisTermInferrer fills the pivot so Explore filters work. Hook-type inference deliberately ignores the `idea` field (models often narrate taxonomy slugs there). Backfill with `php artisan snitch:backfill-analysis-terms`; use `--replace` to strip mirrored catalogue labels from topics and rebuild pivots after a bad merge. Seed via AnalysisTermSeeder (`syncToDatabase` upsert). Production deploy must run `db:seed --class=AnalysisTermSeeder --force` after migrate - Explore pickers read `analysis_terms` and show empty if the table was never seeded. Explore (`/explore`) filters by catalogue slug, platform, `custom_tag`, and free-text `q`. Query params: `hook_types`, `topics`, `visual_crafts` (string or array), plus `custom_tag`, `q`, and `platform`. Feed / contact-cell chips link via `exploreHrefForTerm`: catalogue dimensions use slug filters; custom tags use `custom_tag` with the full raw value (display labels are humanized; never search the truncated chip text).

## Analysis embeddings (NanoGPT)
After a successful analysis, `AnalyzePostJob` dispatches `EmbedPostAnalysisJob`, which embeds concept/hook/idea/visual/topics/custom_tags/how_to_copy/caption via NanoGPT `/embeddings` (`snitch.embeddings.model`, default `text-embedding-3-small`) into `post_analyses.embedding` (+ model/hash). Hide embedding columns from Inertia. Explore ranks `custom_tag` and `q` by cosine similarity over the user's completed reel candidates (exact `custom_tags` JSON matches are boosted to the top). Catalogue slug pickers stay exact pivot filters. If embeddings are disabled or the query embed fails, fall back to JSON/`LIKE` search. Backfill existing rows with `php artisan snitch:backfill-analysis-embeddings` (optional `--sync --force --limit=`). Requires `NANOGPT_API_KEY` and `SNITCH_EMBEDDINGS_ENABLED=true`.
