---
paths:
  - 'app/Services/Billing/**'
  - 'app/Http/Controllers/Settings/BillingController.php'
  - 'app/Http/Controllers/ClaimController.php'
  - 'app/Listeners/HandleStripeWebhook.php'
  - 'app/Policies/TrackedAccountPolicy.php'
  - 'app/Http/Controllers/CompetitorController.php'
  - 'app/Http/Controllers/InfluencerController.php'
  - 'app/Mcp/**'
  - 'routes/ai.php'
  - 'config/billing.php'
  - 'config/subscriptions.php'
  - 'config/cashier.php'
  - 'resources/js/pages/billing/**'
  - 'resources/js/pages/marketing/Pricing.vue'
  - 'resources/js/pages/marketing/Agents.vue'
  - 'resources/js/pages/agents/**'
  - 'resources/js/components/agents/**'
  - 'app/Http/Controllers/AgentsController.php'
  - 'app/Support/McpConnectionGuide.php'
  - 'resources/js/pages/claim/**'
---

# Hybrid usage billing + MCP

## Model
Platform fee (£19/mo via `STRIPE_PRICE_PLATFORM`) plus prepaid usage credits (packs in `config/billing.php`). Seat caps for competitors/influencers are retired - money is the limit. Internal price uses vendor COGS × `price_multiplier` (default 1.3); never show markup/COGS/"30%" in UI or MCP copy - only charged GBP amounts.

## Credits
Agent MCP `create_account` starts at £0. Claiming/confirming (WorkOS bind or web signup) grants `claim_bonus_pence` (£5) once (`idempotency_key` `claim_bonus:{user_id}`). Each paid platform subscription invoice grants `subscription_bonus_pence` (£30) once per invoice (`idempotency_key` `subscription_bonus:invoice:{invoice_id}`) via Stripe `invoice.paid`. Billable jobs require balance strictly greater than `billing.min_run_balance_pence` (default 20p) - platform subscription is optional value (monthly credits), not a hard gate. At/below the floor, throw `InsufficientCreditsException` asking to subscribe for plan value or top up. Manual sync HTTP/MCP endpoints must assert this before `markSyncRunning()` / dispatch so the UI never shows Syncing as in progress when blocked. Shared Inertia `subscription.can_run_billable` drives Sync button disabled state.

## Vendors
Ledger rows are per vendor: `apify`, `nanogpt`, `firecrawl`, `tikhub` (plus `bonus`/`topup`). Apify prefers exact `usageTotalUsd` from run API; NanoGPT/Firecrawl/TikHub use catalog estimates / floors. Billing page and MCP `billing_status` show spend by those four vendors.

## NanoGPT analyze.post / queries
Prefer real upstream usage (Apify `usageTotalUsd`, NanoGPT `usage.prompt_tokens` / `usage.completion_tokens` via `estimateNanoGptChatUsd`, Firecrawl credits, TikHub endpoint floors only when call cost is unknown). Charged amount is always COGS × `usd_to_gbp` × `price_multiplier`, rounded half-up to **0.01p** (£0.0001). Ledger columns are `decimal(14,2)` pence (hundredths of a penny). **No minimum charge** - no `min_charge_pence`, no ceil-to-1p / 0.2p; explicit $0 COGS charges £0. Catalog `floors_usd` / action `floor_usd` are COGS stand-ins when measured usage is missing only - not user charge floors. When NanoGPT tokens are present, bill the token math even if tiny (do not clamp up to the missing-data floor). UI formats usage to 4dp GBP when sub-penny (e.g. £0.0001). Do not show markup/COGS in UI.

## Surfaces
- Authenticated `/billing` - balance, platform subscribe, credit packs, vendor usage, stacked stipple chart of Apify/NanoGPT/Firecrawl/TikHub spend (`UsageBillingService::spendSeries`) with `grain=day|week|month` (default day). Chart bars use coloured stipple dots (`VENDOR_CHART_FILL`); legend uses mini vendor logos on paper plates. Short recent-charges preview (8 rows) links to the full list.
- Authenticated `/billing/charges` (`billing.charges`) - paginated ledger breakdown (`UsageBillingService::paginatedCharges`, 25/page) with vendor / action / days filters; amounts only (no COGS/markup)
- Public + auth `/agents` (MCP connect docs; auth also mints/rotates Sanctum token). `/for-agents` redirects to `/agents`
- MCP register `/mcp/register` (create_account); authenticated `/mcp` (Sanctum bearer)
- Claim `/claim/{token}` then WorkOS login

## MCP discovery loops need a confirm step
Competitor suggest and influencer find only queue discovery. Agents must confirm (`confirm_competitor_suggestions` / `keep_influencer`) or dismiss/discard before ending the session. Unconfirmed competitor suggestions stay pending in cache and on Competitors Index. Document the loop in SnitchServer instructions, tool descriptions, and `McpConnectionGuide` copy.

## MCP environment / queue / brand
`whoami` and `billing_status` include `runtime` from `McpRuntime` (`app_url`, queue pending/failed, warnings). Local `APP_URL` (localhost) is a different DB and credit balance than production (`https://www.snitchsocial.net`). Async MCP tools require `php artisan queue:work`. Status tools return structured `next_step`. `BrandContext::assertReady` hard-blocks `suggest_competitors` / `find_influencers` when brand is missing or website/name blank (empty description stays warning-only on `whoami` / `get_brand`). Soft-warn when name looks unrelated to website host. Dispatch tools accept optional `wait_seconds` (default ~22, max 45 via `McpJobWait`) so agents often get terminal status without extra polls. Never paste Sanctum tokens into public chats; prefer `rotate_token`.

## Stripe
Checkout: product `platform` or `credits` + pack key. Webhook `checkout.session.completed` with `snitch_product=credits` tops up ledger idempotently. Sync prices with `php artisan snitch:stripe-sync-plans`. Env: `STRIPE_PRICE_PLATFORM`, `STRIPE_PRICE_CREDITS_*`. Never commit secrets. Production uses the live SnitchSocial account (`acct_1U2GxgE7lACbEaHl`); sandbox customers (`acct_1U2Gxo2QNFnwAnG2`) must not be stored in prod `users.stripe_id`. `User::createOrGetStripeCustomer()` clears a missing customer id and recreates in the current mode so checkout does not hard-fail after a test/live mix-up.

## Jobs
Do not schedule account sync or winners rescore - agents/users trigger them so usage is intentional. Blog generate may remain scheduled.
