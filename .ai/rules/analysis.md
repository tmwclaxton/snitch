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

## Analysis output is English only
Prompts require US English for every JSON string (concept, idea, topics, how_to_copy, visual, cta, labels). Spoken-word quotes in hook may keep the source language. Evaluator rejects Han/CJK prose so Qwen and similar models cannot persist Chinese analysis fields.

## Taxonomy slugs + custom_tags
Prompt includes the controlled catalogue from database/data/analysis_terms.php (~250 hook_type / topic / visual_craft slugs). Persist matched terms on the analysis_term pivot; store genuine misses in post_analyses.custom_tags. Mirror catalogue labels into topics for glance UI. Unknown slugs are dropped. Missing taxonomy alone must not fail the evaluator. When the model omits slugs but freeform hook/concept/topics clearly name a catalogue term (e.g. "myth-busting" -> myth_bust), AnalysisTermInferrer fills the pivot so Explore filters work. Backfill existing rows with `php artisan snitch:backfill-analysis-terms`. Seed via AnalysisTermSeeder (`syncToDatabase` upsert). Production deploy must run `db:seed --class=AnalysisTermSeeder --force` after migrate - Explore pickers read `analysis_terms` and show empty if the table was never seeded. Explore (`/explore`) filters by catalogue slug, platform, and free-text over hook/concept/idea/visual_summary/topics/custom_tags. Query params: `hook_types`, `topics`, `visual_crafts` (string or array), plus `q` and `platform`. Feed show / contact-cell term chips link to Explore via `exploreHrefForTerm` with the matching filter preselected (custom/freeform tags use `q`).
