---
paths:
  - 'app/Http/Controllers/Admin/**'
  - 'app/Http/Middleware/EnsureAdmin.php'
  - 'app/Http/Middleware/LogMcpToolInvocation.php'
  - 'app/Services/Admin/**'
  - 'app/Models/McpToolInvocation.php'
  - 'resources/js/pages/admin/**'
  - 'routes/web.php'
  - 'routes/ai.php'
  - 'config/snitch.php'
---

# Admin

## Allowlist
`ADMIN_EMAILS` (comma-separated) → `config('snitch.admin_emails')`. `User::isAdmin()` is case-insensitive email match. Empty list means nobody is admin. Shared Inertia `auth.user.is_admin` drives the sidebar Admin link.

## Routes
`GET /admin` (`admin.overview`) is behind `auth` + WorkOS + `EnsureAdmin`, outside brand/product paywall (same idea as billing). Do not expose COGS / markup / profit on customer billing - admin only.

## MCP invocation log
`LogMcpToolInvocation` runs after MCP auth and wraps `tools/call` (including paywall 402s). Table `mcp_tool_invocations` stores tool, ok, error_code, duration_ms, auth (`sanctum`|`passport`). Never log tokens or argument payloads. Charts only have data from deploy forward.
