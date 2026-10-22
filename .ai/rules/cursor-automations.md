---
paths:
  - app/Services/Automations/**
  - app/Mcp/Tools/SaveExecutiveBriefTool.php
  - app/Mcp/Tools/PostChatReplyTool.php
  - app/Http/Middleware/LogAutomationMcpCall.php
  - app/Http/Controllers/Admin/AutomationRunController.php
  - app/Console/Commands/KickoffBriefAutomationsCommand.php
  - docs/cursor-automations/**
  - tests/Feature/Automations/**
  - config/services.php
---

# Cursor Automations (brief + chat)

## Scoped tokens (not a shared GG-style PAT)
Each kickoff creates an `automation_runs` row and a Sanctum token named `automation:{type}:{uuid}` with abilities `automation:brief:read|write` or `automation:chat:read|write`, TTL ~30 minutes (`CURSOR_AUTOMATIONS_TOKEN_TTL_MINUTES`). Revoke on success/failure/timeout/admin. `McpAuth::subjectUser` always returns the token owner for automation tokens and ignores `user_id` in tool args. `LogAutomationMcpCall` rate-limits per user and writes `automation_call_audits` (no payloads).

## Env
- Brief: `CURSOR_BRIEF_WEBHOOK_URL`, `CURSOR_BRIEF_WEBHOOK_TOKEN`
- Chat: `CURSOR_CHAT_WEBHOOK_URL`, `CURSOR_CHAT_WEBHOOK_TOKEN`
- Shared: `CURSOR_AUTOMATIONS_MCP_URL` (default `https://www.snitchsocial.net/mcp`), trigger guard 180s, chat timeout 180s
- Preflight refuses empty or placeholder values (`example.com`, `YOUR_`, `changeme`, …)

## MCP tools
- `save_executive_brief` - automation brief write; requires `headline`, interpretive `narrative` (80-140 words, ≤4 numbers, ≤2 `@handle`-opening sentences), `summary`, and suggestions; validates with `DailyBriefValidator`; on failure keeps current brief and leaves the run open for retry. Page never shows deterministic fact-dump prose as the narrative.
- `post_chat_reply` - automation chat write; `partial=true` streams; `done=true` / `partial=false` finalises

## Prompts for cursor.com
`docs/cursor-automations/brief-prompt.md` and `chat-prompt.md` (generic; user specifics in webhook payload). Cursor.com automations may only expose Memories - prompts must tell the agent to call Snitch MCP over HTTPS JSON-RPC (`POST /mcp`, `Authorization: Bearer <scoped_token>`, `tools/call`). Ops: `snitch:automation-brief {user} --force`, admin Kick off buttons, deploy tag `[kickoff-brief-1-6]`.
