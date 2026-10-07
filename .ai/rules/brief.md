---
paths:
  - app/Services/Brief/**
  - app/Jobs/GenerateWeeklyBriefJob.php
  - app/Console/Commands/GenerateWeeklyBriefsCommand.php
  - app/Http/Controllers/BriefController.php
  - resources/js/pages/brief/**
  - tests/Feature/Brief/**
  - app/Mcp/Tools/GetWeeklyBriefTool.php
  - app/Mcp/Tools/MarkWeeklyBriefIdeaUsedTool.php
  - app/Mcp/Tools/GetGrowthTool.php
  - app/Mcp/Tools/GetMonthlyReportTool.php
  - app/Mcp/Tools/ShareMonthlyReportTool.php
  - app/Mcp/Tools/RevokeMonthlyReportTool.php
  - app/Support/WeeklyBriefPresenter.php
  - app/Http/Controllers/GrowthController.php
  - app/Http/Controllers/MonthlyReportController.php
  - resources/js/pages/growth/**
  - tests/Feature/Mcp/WeeklyBriefAndGrowthToolsTest.php
---

# Weekly brief (Post this next)

## Automatic and free by default
`snitch:generate-weekly-briefs` runs after the Monday sync. `SyncTrackedAccountJob` and `AnalyzePostJob` also call `WeeklyBriefGenerator::queueIfReady` (debounced). Automatic generation never charges credits. Silent no-op when brand profile is missing, a brief for the week already exists, or data is below `snitch.brief` thresholds (competitors, analysed posts in 30d, winner candidates).

## Dashboard and /brief UX
Dashboard `weekly_brief` teaser is null unless a ready brief exists (current or most recent week). Teaser includes up to 3 idea cards + `best_times` inside What should we post? (not a thin top bar), with See full brief to `/brief`. Each teaser idea also sends `visual`, `caption_angle`, and `cta` so the dashboard can show a short how-to from stored copy. How-to is visual + caption_angle only; CTA is a separate line: bold `Call to action:` plus the capitalised CTA. Keep `/brief` and the This week sidebar entry. On `/brief`, put the three idea cards first; Best times + a compact heat grid (about half prior cell height via `.snitch-brief-heat-cell`) below. No empty state or generate button on the dashboard. On `/brief` without a brief, show only the automatic-wait copy. Manual generate is admin-only regenerate (billable when force). Growth / Monthly report use full content width; Monthly report has its own sidebar entry (not a link on Growth).

## MCP
`get_weekly_brief` reads `/brief` (optional `week` as Y-m-d, snapped to Monday) including ideas, best times, history, and `app_url`. `mark_weekly_brief_idea_used` toggles `used_at` the same way as the website. Agents must not force a regenerate. Growth charts and the monthly report are separate tools: `get_growth`, `get_monthly_report`, `share_monthly_report`, `revoke_monthly_report`.

## Force regenerate
Admin POST `brief.generate` with `force` queues `GenerateWeeklyBriefJob` with `billable: true`. Ops may use `snitch:generate-weekly-briefs --force --billable` when charging is intended; omit `--billable` for free ops runs. Non-admins never see the regenerate control (`canRegenerate` is admin-only).

## Idea quality
Feed the LLM the top 5-8 winner candidates by X× usual (30d, tracked accounts including own). Every idea must cite 1-2 `inspired_by_post_ids`; at most 2 ideas may share one source - reassign repeats in code. `hook` is the literal on-screen first line (max ~12 words); scene direction goes in optional `visual`. Instagram CTAs only: comment, save, share, DM, or link in bio - never swipe up. Never borrow named people from competitor captions (for example a competitor member named Jessie); rewrite to "a member's story" via `BorrowedCompetitorNameSanitizer` in normalize and when cleaning stored ideas. Match straight and curly apostrophe possessives (`Jessie's` / `Jessie’s`). Cleaning stored ideas must cover weekly idea rows and any copied `unused_weekly_ideas` on daily brief payloads.

## Report maths use full post history
`MonthlyReportBuilder` and `GrowthMetricsBuilder` compute X× usual against the full post history (same as the dashboard), not a window-only baseline. Past months without a follower snapshot report no followers instead of today's count. Partial-month post counts compare with the same days last month. London month bounds convert to UTC. Unscored winner insights sort last on Postgres. After a maths fix, persist stored monthly reports again (`MonthlyReportBuilder::persist`) so /report does not keep the old payload. `snitch:generate-daily-briefs` refreshes the current-month stored payload after the morning syncs because PDF, share, and audit read the stored row rather than rebuilding.
