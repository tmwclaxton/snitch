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

## MCP list_winners
Scope to in-quota tracked accounts (same as web). Optional request filters: `q`, `platform`, `topics[]`, `limit`. Eager-load analysis hook/how_to_copy/topics. Attach `snitch_url` via `McpAppUrls`. Scoring stays metrics-only; topic/`q` filters are request-scoped soft rank, not a scorer rewrite.
