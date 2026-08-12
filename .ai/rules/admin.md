---
paths:
  - 'app/Http/Controllers/Admin/**'
  - 'app/Http/Middleware/EnsureAdmin.php'
  - 'app/Http/Middleware/LogMcpToolInvocation.php'
  - 'app/Http/Middleware/RejectAdminMcpTools.php'
  - 'app/Support/AdminMcp.php'
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
`GET /admin` (`admin.overview`) is behind `auth` + WorkOS + `EnsureAdmin`, outside brand/product paywall (same idea as billing). Do not expose COGS / markup / profit on customer billing - admin only. **`AdminOverviewService::creditExpirySeries`** (12-month default) charts platform-wide unused `remaining_pence` scheduled to expire by calendar month (stipple bars on Overview); `never_pence` is starter credit with null `expires_at` (excluded from bars, shown in subtitle).

## MCP is never admin
Admin overview, COGS/profit, and other operator tooling are **web-only**. Do not register `App\Mcp\Tools\Admin\*` tools, `admin_*` / `admin.*` tool names, or return platform-wide admin aggregates via MCP. `RejectAdminMcpTools` rejects those `tools/call` names with HTTP 403 / JSON-RPC `-32003`. `whoami` and billing tools must not expose `is_admin` or COGS.

## MCP invocation log
`LogMcpToolInvocation` runs after MCP auth and wraps `tools/call` (including paywall 402s and admin rejects). Table `mcp_tool_invocations` stores tool, ok, error_code, duration_ms, auth (`sanctum`|`passport`). Never log tokens or argument payloads. Charts only have data from deploy forward.
