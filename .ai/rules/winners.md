---
paths:
  - app/Services/Winners/**
  - app/Jobs/ScoreWinnersJob.php
  - app/Http/Controllers/WinnerController.php
---

# Winners

## Rescore must stay cheap
ScoreWinnersJob can touch up to 100 posts. Do not call NanoGPT for every passer on rescore. Reuse existing WinnerInsight.how_to_copy, then PostAnalysis.how_to_copy, and only generate (LLM or deterministic fallback) when both are missing. Rescoring an already-scored tear sheet should be metrics + DB writes.
