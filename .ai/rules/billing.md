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
Agent MCP `create_account` starts at £0. Claiming/confirming (WorkOS bind or web signup) grants `claim_bonus_pence` (£5) once (`idempotency_key` `claim_bonus:{user_id}`). Billable jobs require active platform subscription + positive credit balance.

## Vendors
Ledger rows are per vendor: `apify`, `nanogpt`, `firecrawl` (plus `bonus`/`topup`). Apify prefers exact `usageTotalUsd` from run API; NanoGPT/Firecrawl use catalog estimates. Billing page and MCP `billing_status` show spend by those three vendors.

## Surfaces
- Authenticated `/billing` - balance, platform subscribe, credit packs, vendor usage
- Public + auth `/agents` (MCP connect docs; auth also mints/rotates Sanctum token). `/for-agents` redirects to `/agents`
- MCP register `/mcp/register` (create_account); authenticated `/mcp` (Sanctum bearer)
- Claim `/claim/{token}` then WorkOS login

## Stripe
Checkout: product `platform` or `credits` + pack key. Webhook `checkout.session.completed` with `snitch_product=credits` tops up ledger idempotently. Sync prices with `php artisan snitch:stripe-sync-plans`. Env: `STRIPE_PRICE_PLATFORM`, `STRIPE_PRICE_CREDITS_*`. Never commit secrets.

## Jobs
Do not schedule account sync or winners rescore - agents/users trigger them so usage is intentional. Blog generate may remain scheduled.
