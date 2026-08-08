---
paths:
  - 'app/Services/Analysis/**'
  - 'database/data/analysis_terms.php'
  - 'app/Http/Controllers/ExploreController.php'
  - 'resources/js/pages/explore/**'
---

# Analysis

## Analysis is concept-first not transcript dump
Persist concept, topics, how_to_copy, hook, idea, visual, music/SFX. Evaluator rejects caption echo and vague filler. Prompts forbid long script regurgitation and invented SFX.

## Analysis output is English only
Prompts require US English for every JSON string (concept, idea, topics, how_to_copy, visual, cta, labels). Spoken-word quotes in hook may keep the source language. Evaluator rejects Han/CJK prose so Qwen and similar models cannot persist Chinese analysis fields.

## Taxonomy slugs + custom_tags
Prompt includes the controlled catalogue from database/data/analysis_terms.php (~250 hook_type / topic / visual_craft slugs). Persist matched terms on the analysis_term pivot; store genuine misses in post_analyses.custom_tags. Mirror catalogue labels into topics for glance UI. Unknown slugs are dropped. Missing taxonomy alone must not fail the evaluator. When the model omits slugs but freeform hook/concept/topics clearly name a catalogue term (e.g. "myth-busting" -> myth_bust), AnalysisTermInferrer fills the pivot so Explore filters work. Backfill existing rows with `php artisan snitch:backfill-analysis-terms`. Seed via AnalysisTermSeeder. Explore (`/explore`) filters by catalogue slug, platform, and free-text over hook/concept/idea/visual_summary/topics/custom_tags.
