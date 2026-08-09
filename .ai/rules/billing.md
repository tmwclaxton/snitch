---
paths:
  - 'app/Services/Billing/**'
  - 'app/Http/Controllers/Settings/BillingController.php'
  - 'app/Policies/TrackedAccountPolicy.php'
  - 'app/Http/Controllers/CompetitorController.php'
  - 'app/Http/Controllers/InfluencerController.php'
  - 'config/subscriptions.php'
  - 'config/cashier.php'
  - 'resources/js/pages/billing/**'
  - 'resources/js/pages/marketing/Pricing.vue'
---

# Stripe billing entitlements

## Plans
Trial is app-level via `users.trial_ends_at` (Cashier generic trial), not a card-required Stripe trial. After expiry with no subscription, Free allows 3 competitors and 3 influencers. Basic (£20/mo or £192/yr) = 10 each, Pro (£99/mo or £950.40/yr) = 50 each. Yearly is 20% off monthly. Active Basic/Pro subscription always wins over Free/trial.

## Enforcement
Resolve limits with `PlanEntitlementService`. Competitor and influencer slots are separate (`tracked_accounts.kind`). Gate competitor creates via `TrackedAccountPolicy::create` / `canAddCompetitors` in `CompetitorController`. Gate influencer Keep via `canAddInfluencers` in `InfluencerController`. Existing handles may update without consuming a slot.

## Over-quota accounts
When a user has more accounts of a kind than that kind's limit (e.g. grandfathered competitors), the oldest accounts of that kind by `id` keep in-quota slots. `inQuotaTrackedAccountIds` unions in-quota competitors and influencers. Excess accounts stay listed and removable, but are grayed out (`in_quota: false`), cannot sync, and their posts are excluded from feed / explore / winners / backlog / dashboard. Competitor show returns empty posts/winners for over-quota accounts. `PostPolicy::view` also blocks deep links to those reels. Do not auto-delete over-quota rows.

## Surfaces
Public marketing page: `/pricing`. Authenticated billing UI: `/billing` (sidebar account links, above Settings - not nested under Settings nav). Old `/settings/billing` redirects to `/billing`.

## Checkout / UI
Checkout and Customer Portal must use `Inertia::location($url)` so Inertia Form posts leave the app for Stripe (plain 302 is ignored). During trial, entitlements look like Basic but the user is not subscribed - show Start Basic and Upgrade to Pro, keyed off `subscribed`, not trial plan name.

## Stripe ops
Price IDs live in env (`STRIPE_PRICE_BASIC`, `STRIPE_PRICE_PRO`, `STRIPE_PRICE_BASIC_YEARLY`, `STRIPE_PRICE_PRO_YEARLY`). Create/attach them with `php artisan snitch:stripe-sync-plans` (reuses existing monthly products when env monthly IDs are set). Checkout posts `plan` + `interval` (`month`|`year`). Webhook path is `/stripe/webhook` (CSRF exempt). Never commit Stripe secrets.
