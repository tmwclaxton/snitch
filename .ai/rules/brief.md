---
paths:
  - app/Services/Brief/**
  - app/Jobs/GenerateWeeklyBriefJob.php
  - app/Console/Commands/GenerateWeeklyBriefsCommand.php
  - app/Http/Controllers/BriefController.php
  - resources/js/pages/brief/**
  - tests/Feature/Brief/**
---

# Weekly brief (Post this next)

## Automatic and free by default
`snitch:generate-weekly-briefs` runs after the Monday sync. `SyncTrackedAccountJob` and `AnalyzePostJob` also call `WeeklyBriefGenerator::queueIfReady` (debounced). Automatic generation never charges credits. Silent no-op when brand profile is missing, a brief for the week already exists, or data is below `snitch.brief` thresholds (competitors, analysed posts in 30d, winner candidates).

## Dashboard and /brief UX
Dashboard `weekly_brief` teaser is null unless a ready brief exists (current or most recent week). No empty state or generate button on the dashboard. On `/brief` without a brief, show only the automatic-wait copy. Manual generate is admin-only regenerate (billable when force).

## Force regenerate
Admin POST `brief.generate` with `force` queues `GenerateWeeklyBriefJob` with `billable: true`. Ops may use `snitch:generate-weekly-briefs --force --billable` when charging is intended; omit `--billable` for free ops runs.
