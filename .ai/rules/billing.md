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
  - 'app/Http/Controllers/Marketing/PricingController.php'
  - 'app/Support/McpConnectionGuide.php'
  - 'resources/js/pages/claim/**'
---

# Hybrid usage billing + MCP

## Model
Platform fee (£19/mo via `STRIPE_PRICE_PLATFORM`) plus prepaid usage credits (packs in `config/billing.php`). Seat caps for competitors/influencers are retired - money is the limit. Internal price uses vendor COGS × `price_multiplier` (default 1.75 = base + 75%); never show markup/COGS/"75%" in UI or MCP copy - only charged GBP amounts.

## Credits
Agent MCP `create_account` starts at £0. Claiming/confirming (WorkOS bind or web signup) grants `claim_bonus_pence` (£5) once (`idempotency_key` `claim_bonus:{user_id}`). Each paid platform subscription invoice grants `subscription_bonus_pence` (£30) once per invoice (`idempotency_key` `subscription_bonus:invoice:{invoice_id}`) via Stripe `invoice.paid`.

### Paywall (after free starter)
- Free starter (£5 claim bonus) may be spent without a platform plan. Unsubscribed product access counts **only** claim_bonus remaining (never expires).
- Once starter is exhausted (`credit_balances.starter_allowance_exhausted`, set when claim remaining hits the floor), an **active paid platform subscription** is required for product use (web data screens + MCP tools). Top-up alone must not bypass this.
- **Top-ups require a paid plan** (HTTP checkout, MCP `create_credit_checkout`, Stripe webhook ignores credit sessions without a plan).
- With a plan, access needs spendable unexpired balance above `billing.min_run_balance_pence` (default 20p). Hitting the floor is the same blocked experience as over-monthly-spend.
- UI: shared Inertia `subscription.paywall` + `BillingPaywall` blur/modal (billing routes exempt). Mutations under `EnsureProductAccess` redirect to Billing. Non-Inertia JSON/XHR under that middleware returns 402 with no product payload. Safe Inertia GETs may still render the shell + paywall UI, but controllers must omit product data (empty stubs / redirects for show pages) - security is server-side omission, not CSS. Shared `competitors_used` / `influencers_used` are zeroed when blocked. MCP: `EnsureMcpProductAccess` allows only billing/auth tools when blocked; product list/get tools also call `McpAuth::requireProductAccess`.

### Credit lot expiry (`expires_at` + `remaining_pence` on ledger credits)
- `claim_bonus`: never expires (`expires_at` null).
- `subscription_bonus`: expires at end of the calendar month granted (no month-to-month rollover).
- `credits.topup`: expires `billing.topup_expiry_months` (default 3) after purchase.
- Charges FIFO-consume unexpired lots (soonest expiry first). `balancePence` syncs by zeroing expired remaining.

Billable jobs / MCP product tools use `UsageBillingService::assertCanAccessProduct` / `canRun`. Manual sync HTTP/MCP endpoints must assert before `markSyncRunning()` / dispatch. Shared Inertia `subscription.can_run_billable` is `!paywall.blocked`.

## Vendors
Ledger rows are per vendor: `apify`, `nanogpt`, `firecrawl`, `tikhub`, `snitch` (plus `bonus`/`topup`). Apify prefers exact `usageTotalUsd` from run API; NanoGPT/Firecrawl/TikHub use catalog estimates / floors. Spend chart / "Usage this period" include the four scrape/LLM vendors plus `snitch` product fees (`UsageBillingService::spendVendorKeys`). Snitch legend / usage row mark must be `/images/brand/mascot-mark.png` (same as favicon / `AppLogoIcon`), not a separate vendors/snitch.svg.

## NanoGPT analyze.post / queries
Prefer real upstream usage (Apify `usageTotalUsd`, NanoGPT `usage.prompt_tokens` / `usage.completion_tokens` via `estimateNanoGptChatUsd`, Firecrawl credits, TikHub endpoint floors only when call cost is unknown). Look up NanoGPT model rates via the `models` array key (not `config("…models.{$model}")`) - ids like `qwen3.7-flash` contain dots and break Laravel dotted config paths (falls back to the cheap floor and undercharges). Charged amount is usually COGS × `usd_to_gbp` × `price_multiplier`, rounded half-up to **0.01p** (£0.0001). Product fees with exact user charges use `billing.actions[$action]` by array key (action names contain dots). `explore.search` is proportional: `min(max_pence, (result_count / results_for_max_pence) * max_pence)` with defaults `max_pence=0.5`, `results_for_max_pence=24`; **0 results = 0p** (no ledger write). `explore.view` stays a flat `fixed_pence` (0.1p). Ledger columns are `decimal(14,2)` pence (hundredths of a penny). **No minimum charge** - no `min_charge_pence`, no ceil-to-1p / 0.2p. Explicit $0 COGS prices to £0 and `UsageBillingService::charge` **skips the ledger write** (returns null) so preliminary Apify $0 usage does not create noise rows or burn `apify:{runId}` idempotency keys. Catalog `floors_usd` / action `floor_usd` are COGS stand-ins when measured usage is **null/missing** only - not user charge floors, and not applied when COGS is explicit `0`. When NanoGPT tokens are present, bill the token math even if tiny (do not clamp up to the missing-data floor). Billing recent/charges UI omits `amount_pence = 0` rows. UI money via `formatPenceAsGbp`: default `auto` shows only required decimals up to 4dp (£6.30, £0.0103) for balance, ledger charges, spend chart, vendor totals. Catalog/subscription/top-up use `{ decimals: 2 }` (£19, £30, £10) - never forced 4dp. Do not show markup/COGS in UI.

## Explore product fees
`ExploreBillingService`: `explore.search` on Explore `q` / `custom_tag` (and MCP `explore_posts` with `q`); idempotent per user + normalised query; charge scales linearly with result count up to 0.5p (0 results = 0p). `explore.view` when opening a completed reel whose `social_account_id` is not among the user's tracked snitches (`kind=competitor`); free for tracked snitches; idempotent `explore.view:{user_id}:{post_id}`. Insufficient credits → Billing (web) or MCP error.

## Surfaces
- Authenticated `/billing` - balance, platform subscribe, credit packs, vendor usage, stacked stipple chart of Apify/NanoGPT/Firecrawl/TikHub/Snitch spend (`UsageBillingService::spendSeries`) with `grain=day|week|month` (default day). Chart bars use coloured stipple dots (`VENDOR_CHART_FILL`) with a paper hover tip naming the vendor (+ amount / period); legend uses mini vendor logos on paper plates (Snitch = mascot-mark). Short recent-charges preview (8 rows) links to the full list. **`UsageBillingService::creditExpiryBreakdown`** drives a "When your credit expires" scrap panel: unexpired lots grouped by expiry bucket (soonest first, never last), per-lot labels (starter / plan / top-up), amounts via `formatPenceAsGbp`, and `topup_expiry_months` from config.
- Authenticated `/billing/charges` (`billing.charges`) - paginated ledger breakdown (`UsageBillingService::paginatedCharges`, 25/page) with vendor / action / days filters; amounts only (no COGS/markup). Rows include derived `description` + optional `link` (see below). Same **`creditExpiryBreakdown`** (compact) plus **`creditExpiryFilterNote`** when filtering `claim_bonus`, `subscription_bonus`, or `credits.topup`.
- Public `/pricing` (`PricingController`) - platform fee + credit packs copy, plus **live tool averages** from `UsageBillingService::globalVendorAverages()` (ledger mean charge per run per spend vendor across all users; same vendor keys as billing `summary()`). Display averages with `formatPenceAsGbp(..., { decimals: 4 })`. Do not invent a second catalog of fake averages.
- Public + auth `/agents` (MCP connect docs; auth also mints/rotates Sanctum token). `/for-agents` redirects to `/agents`

## Charge descriptions and links
Ledger `meta` JSON carries link targets (`post_id`, `tracked_account_id`, `social_account_id`, `suggest_id`, `run_id`, `platform`, `post_type`, `handle`, `account_kind`). `LedgerChargePresenter` derives a human `description` and optional `link` `{type,id?,label}` in `UsageBillingService::mapLedgerEntry` for billing preview + charges table. Prefer storing context IDs at charge time; optional `meta.description` overrides the derived line. Old rows without meta fall back to action labels (e.g. "Analyzed post"). NanoGPT `embed.analysis` shows as "Indexed post analysis"; new rows store `post_id`, and legacy rows that only have `post_analysis_id` still resolve a feed link via that id. Explore search/view get dedicated copy; search links to Explore. Link types: `post` → feed show, `tracked_account` → competitors show (also influencers), `competitors` / `influencers` / `brand` / `explore` index pages. Never show markup/COGS in UI.
- MCP register `/mcp/register` (create_account); authenticated `/mcp` (Sanctum bearer)
- Claim `/claim/{token}` then WorkOS login

## MCP discovery loops need a confirm step
Snitch suggest and influencer find only queue discovery. Agents must confirm (`confirm_competitor_suggestions` / `keep_influencer`) or dismiss/discard before ending the session. Unconfirmed snitch suggestions stay pending in cache and on Snitches Index. Document the loop in SnitchServer instructions, tool descriptions, and `McpConnectionGuide` copy. Authenticated MCP exposes `workflow_guide` (call first; param `workflow`, alias `topic`) with ordered steps, confirm/keep rules, and short `wait_seconds` tips for local artisan serve. Status tools may omit run ids to follow the latest job: `influencer_search_status` (`run_id`), `autofill_status` (`autofill_id`), `suggest_competitors_status` (`suggest_id`, falls back to active then latest). `remove_competitor` / `sync_competitor` / `remove_influencer` accept `tracked_account_id` plus aliases matching list payloads (`competitor_id`/`influencer_id`/`id`). Brand switch via `update_brand` / autofill does **not** clear tracked competitors/influencers - agents must `remove_*` prior-niche accounts.

## MCP environment / queue / brand
`whoami` and `billing_status` include `runtime` from `McpRuntime` (`app_url`, queue pending/failed, warnings). Local `APP_URL` (localhost) is a different DB and credit balance than production (`https://www.snitchsocial.net`). Async MCP tools require `php artisan queue:work`. Status tools return structured `next_step`. `BrandContext::assertReady` hard-blocks `suggest_competitors` / `find_influencers` when brand is missing or website/name blank, or when niche is blank (description, `competitor_brief`, or per-run suggest `brief`). Empty description warns on `whoami` / `get_brand`. Soft-warn when name looks unrelated to website host. Competitor suggest is niche-first - weak brand names must not lead Firecrawl; web modal / MCP may pass `platforms` + `brief`. Dispatch tools (`suggest_competitors`, `find_influencers`, `start_brand_autofill`) default `wait_seconds=0` so remote MCP gateways (Claude.ai ~10-15s timeout) return immediately; poll the matching status tool until terminal. Pass `wait_seconds` up to 45 for blocking waits in Cursor/local. `McpJobWait` must call `set_time_limit` for non-zero waits - PHP's default 30s max_execution_time otherwise fatals mid-wait with HTTP 500. Local `composer run dev` / `php artisan serve` is single-threaded: a long MCP tools/call or SSE hold stalls concurrent browser requests (dashboard). Document this in `McpRuntime` localhost warnings and `McpConnectionGuide`; do not treat it as a session-lock bug (MCP uses Sanctum bearer, not the web session). Production nginx + php-fpm is multi-worker. `AutofillBrandFromWebsiteJob` persists extracted fields onto `BrandProfile` on completion so MCP `get_brand` reflects autofill without a manual `update_brand`. Never paste Sanctum tokens into public chats; prefer `rotate_token`.

## Stripe
Checkout: product `platform` or `credits` + pack key. Success URLs include `session_id={CHECKOUT_SESSION_ID}`; `/billing?checkout=success` runs `StripeCheckoutSyncService` so the local Cashier subscription (and subscription bonus / credit top-up) syncs on return without waiting for webhooks. Webhook `checkout.session.completed` with `snitch_product=credits` still tops up ledger idempotently; Cashier still handles `customer.subscription.*`. Locally keep Stripe CLI forwarding: `stripe listen --forward-to localhost:8000/stripe/webhook` and put the printed `whsec_...` in `STRIPE_WEBHOOK_SECRET` (CLI secret differs from Dashboard). Sync prices with `php artisan snitch:stripe-sync-plans`. Env: `STRIPE_PRICE_PLATFORM`, `STRIPE_PRICE_CREDITS_*`. Never commit secrets. Production uses the live SnitchSocial account (`acct_1U2GxgE7lACbEaHl`); sandbox customers (`acct_1U2Gxo2QNFnwAnG2`) must not be stored in prod `users.stripe_id`. `User::createOrGetStripeCustomer()` clears a missing customer id and recreates in the current mode so checkout does not hard-fail after a test/live mix-up.

## Jobs
Do not schedule account sync or winners rescore - agents/users trigger them so usage is intentional. Blog generate may remain scheduled.
