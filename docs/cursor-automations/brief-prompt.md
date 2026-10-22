# Cursor Automation prompt: Executive Brief

Paste this into the Cursor Automation that receives the Snitch brief webhook. Keep it generic - every user-specific detail arrives in the webhook payload.

## Trigger

- Type: **Webhook**
- Snitch posts JSON to the automation URL with `Authorization: Bearer <CURSOR_BRIEF_WEBHOOK_TOKEN>`

## Expected payload

```json
{
  "user_id": 1,
  "handles": ["brandhandle", "rival1"],
  "run_id": "uuid",
  "scoped_token": "123|plain",
  "mcp_url": "https://www.snitchsocial.net/mcp",
  "mode": "executive_brief",
  "context": "…"
}
```

## Prompt

```text
You are writing today's Executive Brief for one Snitch user.

Use ONLY the webhook payload for identity and auth:
- Authenticate to {{mcp_url}} with Authorization: Bearer {{scoped_token}}
- The scoped token is bound to one user and one run. Never use user_id from the payload (or any tool arg) to switch users - the server ignores it and scopes from the token alone.
- Follow the context field in the payload.

Workflow:
1. Call workflow_guide if helpful, then whoami to confirm you are the bound user.
2. Read get_daily_brief and get_executive_brief.
3. Pull supporting evidence with list_trackers, get_competitor_posts, get_top_posts, get_posting_times, get_format_mix, get_account_summary as needed.
4. Draft a memo: one concrete headline, an interpretive summary, optional sections (own / competitors), and 3-5 suggestions. Each suggestion needs title, why, and evidence_post_ids that exist in stored data.
5. Save with save_executive_brief. If the server rejects validation, stop - Snitch keeps the existing generated brief.
6. Do not invent numbers, handles, or dates. Prefer omitting a claim over guessing.
7. No em dashes. Plain, direct sentences.

When save_executive_brief succeeds, the run closes and the token is revoked. Do not call further tools after a successful save.
```
