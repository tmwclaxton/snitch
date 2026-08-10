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
Platform fee (£19/mo via `STRIPE_PRICE_PLATFORM`) plus prepaid usage credits (packs in `config/billing.php`). Seat caps for competitors/influencers are retired - money is the limit. Internal price uses vendor COGS × `price_multiplier` (default 1.4); never show markup/COGS/"40%" in UI or MCP copy - only charged GBP amounts.

## Credits
Agent MCP `create_account` starts at £0. Claiming/confirming (WorkOS bind or web signup) grants `claim_bonus_pence` (£5) once (`idempotency_key` `claim_bonus:{user_id}`). Each paid platform subscription invoice grants `subscription_bonus_pence` (£30) once per invoice (`idempotency_key` `subscription_bonus:invoice:{invoice_id}`) via Stripe `invoice.paid`. Billable jobs require balance strictly greater than `billing.min_run_balance_pence` (default 20p) - platform subscription is optional value (monthly credits), not a hard gate. At/below the floor, throw `InsufficientCreditsException` asking to subscribe for plan value or top up. Manual sync HTTP/MCP endpoints must assert this before `markSyncRunning()` / dispatch so the UI never shows Syncing as in progress when blocked. Shared Inertia `subscription.can_run_billable` drives Sync button disabled state.

## Vendors
Ledger rows are per vendor: `apify`, `nanogpt`, `firecrawl`, `tikhub` (plus `bonus`/`topup`). Apify prefers exact `usageTotalUsd` from run API; NanoGPT/Firecrawl/TikHub use catalog estimates / floors. Billing page and MCP `billing_status` show spend by those four vendors.

## NanoGPT analyze.post
Prefer real `usage.prompt_tokens` / `usage.completion_tokens` from the NanoGPT chat response (via `VideoAnalysisService` → `estimateNanoGptChatUsd`). When tokens are missing or negligible, the `video_analysis` / `analyze.post` floor is **0.0045 USD** (~0.5p after `usd_to_gbp` × `price_multiplier`; ledger ceils to 1p). Do not show markup/COGS in UI.

## Surfaces
- Authenticated `/billing` - balance, platform subscribe, credit packs, vendor usage, stacked stipple chart of Apify/NanoGPT/Firecrawl/TikHub spend (`UsageBillingService::spendSeries`) with `grain=day|week|month` (default day), and a short recent-charges preview (8 rows) with link to the full list
- Authenticated `/billing/charges` (`billing.charges`) - paginated ledger breakdown (`UsageBillingService::paginatedCharges`, 25/page) with vendor / action / days filters; amounts only (no COGS/markup)
- Public + auth `/agents` (MCP connect docs; auth also mints/rotates Sanctum token). `/for-agents` redirects to `/agents`
- MCP register `/mcp/register` (create_account); authenticated `/mcp` (Sanctum bearer)
- Claim `/claim/{token}` then WorkOS login

## MCP discovery loops need a confirm step
Competitor suggest and influencer find only queue discovery. Agents must confirm (`confirm_competitor_suggestions` / `keep_influencer`) or dismiss/discard before ending the session. Unconfirmed competitor suggestions stay pending in cache and on Competitors Index. Document the loop in SnitchServer instructions, tool descriptions, and `McpConnectionGuide` copy.

## Stripe
Checkout: product `platform` or `credits` + pack key. Webhook `checkout.session.completed` with `snitch_product=credits` tops up ledger idempotently. Sync prices with `php artisan snitch:stripe-sync-plans`. Env: `STRIPE_PRICE_PLATFORM`, `STRIPE_PRICE_CREDITS_*`. Never commit secrets. Production uses the live SnitchSocial account (`acct_1U2GxgE7lACbEaHl`); sandbox customers (`acct_1U2Gxo2QNFnwAnG2`) must not be stored in prod `users.stripe_id`. `User::createOrGetStripeCustomer()` clears a missing customer id and recreates in the current mode so checkout does not hard-fail after a test/live mix-up.

## Jobs
Do not schedule account sync or winners rescore - agents/users trigger them so usage is intentional. Blog generate may remain scheduled.
