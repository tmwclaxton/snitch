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
`users.daily_brief_enabled` defaults false. User 1 is enabled in a data migration. One UK morning pipeline (UTC, BST = UTC+1): `snitch:refresh-followers` at 06:00 UTC, `snitch:sync-accounts` at 06:15 UTC, `snitch:generate-daily-briefs` at 06:25 UTC. Do not schedule `snitch:daily-refresh` - that command stays for ops only. For `daily_brief_enabled` users, `snitch:sync-accounts` folds in the light scrape: Instagram own + competitor trackers, `force=true`, `postsLimit=6`, `recencyDays=30`, `resolveProfile=false`. Everyone else keeps the 7-day `isDueForSync` gate. Follower snapshots are calendar-day (interval 1) from the 06:00 job, so do not queue a second `RefreshFollowerCountJob` from sync. Ads Firecrawl refresh is throttled to once per `ads_refresh_every_days` (3). Same `canRun` gate as weekly sync; skip when it fails. User 1 / admins bypass the gate.

`snitch:generate-daily-briefs` at `snitch.daily_brief.generate_time` (06:25 UTC / 07:25 Europe/London) writes one `daily_briefs` row per user per London date after queued sync jobs have started to drain. Automatic generation is always free. `--force` updates in place (no hard delete). Soft-delete the row if it must be removed.

## Facts in PHP, narrative from the LLM
`DailyBriefFactsBuilder` computes followers, posts, times-usual, views-vs-usual for hidden-like Reels, format mix, best times, unused weekly ideas, ads, and sync health. Each account also gets `cadence` (posts in the last 7 days, days since last post, distinct posting days, `posted_every_day_last_7` for 7/7 London days, `posted_almost_daily_last_7` for 6+) plus `best_post_7d`. Root facts include `top_competitor_hit_7d` (posts inside the 7-day window only) and `cadence_gaps` (`own_is_longest`, `longest_handle`). Brand `own_handles` always join `allowed_handles` even when there is no `is_own_account` tracker. Never let the LLM invent numbers. Scene-description hooks fall back to the caption opening line. Best-time slots with fewer than 3 posts are labelled "early signal". Missing day-on-day followers say "Daily tracking starts today". Empty ads stay visible as "No ads found". Quiet or empty competitors go under Things to watch, not as moves. `sync_failed` is not quiet and not empty: say the refresh failed. `sync_empty` is only a successful empty scrape with no stored posts. The "Competitor posts in the last day" tile uses `format_mix.competitor_posts_24h` and a note from `top_competitor_hit_24h` (best of `posts_last_24h`). If there are none, the note is "none in the last day". Do not use `top_competitor_hit` / 30-day `standout_winners` as that last-day note, or as a "best this week" figure.

`DailyBriefValidator` checks handles, post ids, numbers, 3-5 actions, no ellipsis, hook word cap, and cadence claims. Brand / own handles (`facts.brand.own_handles`, `facts.own.handle`) are always allowed. Numbers in prose that match facts (followers, deltas, counts) are allowed, including a trailing sentence period (`97.` = `97`). Never mention internal post ids in copy (`post 218`, `post #218`); rewrite those to a short description (hook + format) and keep the id only in `related_post_ids`. Reject leftover internal-id phrasing. Reject "posts daily" / "every day" unless `posted_every_day_last_7`. Reject "almost daily" / "nearly daily" / "almost every day" / "posts most days" unless `posted_almost_daily_last_7`. Reject follower "last 7 days" / "this week" wording unless `followers_change_7d.from_date` is exactly 7 days before `brief_date`; use the change `label` instead. Reject "best post this week / last 7 days" figures that are not on `posts_last_7d` / `best_post_7d` / `top_competitor_hit_7d`. Reject "longest gap" claims unless `cadence_gaps.own_is_longest`. Each model HTTP call retries on 429 / 5xx (`snitch.daily_brief.llm_tries`, default 4) with backoff and honours `Retry-After` (capped). Retry the LLM once with the violations listed. If still invalid or the model call fails, drop bad actions, clear invalid prose, and fill with deterministic fallback unless a ready LLM brief already exists for that date: never replace that row with fallback, keep it and log. Fallback copy pluralises counts (`1 time`, not `1 times`). It may call a post a hit or use it as Reel inspiration only when times-usual / views-vs-usual is greater than 1.0; otherwise pick the best in-window format or omit. Store `llm_attempts` and diagnostics on the payload. `snitch:generate-daily-briefs` also persists the current-month stored monthly report after briefs so PDF / share / audit do not keep a stale stored payload.

## Surfaces
`/today` is the first Platform sidebar item. Actions toggle `done_at` in the payload via PATCH. Empty copy: "Your first daily summary arrives at 7am". Admin-only regenerate. MCP `get_daily_brief` and `snitch:daily-brief {user} --json` are read-only. Email (`users.daily_brief_email`) stays off for everyone, including user 1.
