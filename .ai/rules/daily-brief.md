---
paths:
  - app/Services/Brief/DailyBrief*.php
  - app/Jobs/GenerateDailyBriefJob.php
  - app/Console/Commands/DailyRefreshCommand.php
  - app/Console/Commands/GenerateDailyBriefsCommand.php
  - app/Console/Commands/ShowDailyBriefCommand.php
  - app/Http/Controllers/DailyBriefController.php
  - app/Mail/DailyBriefMail.php
  - app/Mcp/Tools/GetDailyBriefTool.php
  - app/Support/DailyBriefPresenter.php
  - resources/js/pages/today/**
  - tests/Feature/DailyBrief/**
  - tests/Feature/Mcp/GetDailyBriefToolTest.php
---

# Daily executive summary (Today)

## Opt-in refresh, then a free brief
`users.daily_brief_enabled` defaults false. User 1 is enabled in a data migration. `snitch:daily-refresh` at `snitch.daily_brief.refresh_time` (06:00 Europe/London) is the opt-in exception to "do not schedule account sync": Instagram own + competitor trackers only, `force=true`, `postsLimit=6`, `recencyDays=30`, `resolveProfile=false`. Follower snapshots run daily via `RefreshFollowerCountJob` with `force=true` (bypasses the 6-day interval, not a same-day second scrape). Ads Firecrawl refresh is throttled to once per `ads_refresh_every_days` (3). Same `canRun` gate as weekly sync; skip and log when it fails. User 1 / admins bypass the gate.

`snitch:generate-daily-briefs` at `snitch.daily_brief.generate_time` (07:00 Europe/London) writes one `daily_briefs` row per user per London date. Automatic generation is always free. `--force` updates in place (no hard delete). Soft-delete the row if it must be removed.

## Facts in PHP, narrative from the LLM
`DailyBriefFactsBuilder` computes followers, posts, times-usual, views-vs-usual for hidden-like Reels, format mix, best times, unused weekly ideas, ads, and sync health. Never let the LLM invent numbers. Scene-description hooks fall back to the caption opening line. Best-time slots with fewer than 3 posts are labelled "early signal". Missing day-on-day followers say "Daily tracking starts today". Empty ads stay visible as "No ads found". Quiet or empty competitors go under Things to watch, not as moves.

`DailyBriefValidator` checks handles, post ids, numbers, 3-5 actions, no ellipsis, hook word cap. Retry the LLM once with the violations listed. If still invalid, drop bad actions and fill with deterministic fallback. Store `llm_attempts` and diagnostics on the payload.

## Surfaces
`/today` is the first Platform sidebar item. Actions toggle `done_at` in the payload via PATCH. Empty copy: "Your first daily summary arrives at 7am". Admin-only regenerate. MCP `get_daily_brief` and `snitch:daily-brief {user} --json` are read-only. Email (`users.daily_brief_email`) stays off for everyone, including user 1.
