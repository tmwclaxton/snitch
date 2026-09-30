---
paths:
  - app/Services/Winners/**
  - app/Jobs/ScoreWinnersJob.php
  - app/Http/Controllers/WinnerController.php
  - app/Mcp/Tools/ListWinnersTool.php
---

# Winners

## Rescore must stay cheap
ScoreWinnersJob can touch up to 100 posts. Do not call NanoGPT for every passer on rescore. Reuse existing WinnerInsight.how_to_copy, then PostAnalysis.how_to_copy, and only generate (LLM or deterministic fallback) when both are missing. Rescoring an already-scored tear sheet should be metrics + DB writes.

## ScoreWinnersJob runId must survive unserialize
`runId` is required for cache status keys. Older queued payloads may omit it; `__wakeup` / `ensureRunId()` mint one so handle/failed never touch an uninitialized typed property.

## Winners live on the dashboard
`/winners` redirects to `/dashboard#performance`. Winning posts (Winners/Flops cards) render in the How are they performing? section. Do not re-add a Winners sidebar entry. Rescore/status/rules routes may remain for jobs and MCP.

## Tear sheet opens the post
Legacy `resources/js/pages/winners/Index.vue` may still exist on disk; product navigation must not link to it. Dashboard WinnerCard opens `/feed/{post}`.

## MCP list_winners
Scope to in-quota tracked accounts (same as web). Optional request filters: `q`, `platform`, `topics[]`, `limit`. Eager-load analysis hook/how_to_copy/topics. Attach `snitch_url` via `McpAppUrls`. Scoring stays metrics-only; topic/`q` filters are request-scoped soft rank, not a scorer rewrite.
