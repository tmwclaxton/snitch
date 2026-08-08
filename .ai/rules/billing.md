---
paths:
  - 'app/Services/Billing/**'
  - 'app/Http/Controllers/Settings/BillingController.php'
  - 'app/Policies/TrackedAccountPolicy.php'
  - 'app/Http/Controllers/CompetitorController.php'
  - 'config/subscriptions.php'
  - 'config/cashier.php'
  - 'resources/js/pages/billing/**'
  - 'resources/js/pages/marketing/Pricing.vue'
---

# Stripe billing entitlements

## Plans
Trial is app-level via `users.trial_ends_at` (Cashier generic trial), not a card-required Stripe trial. After expiry with no subscription, Free allows 3 competitors. Basic (£20) = 10, Pro (£99) = 50. Active Basic/Pro subscription always wins over Free/trial.

## Enforcement
Resolve limits with `PlanEntitlementService`. Gate new tracked accounts in `TrackedAccountPolicy::create` and hard-check net-new creates in `CompetitorController::store` / `confirmSuggestions`. Existing handles may update without consuming a slot.

## Surfaces
Public marketing page: `/pricing`. Authenticated billing UI: `/billing` (sidebar account links, above Settings - not nested under Settings nav). Old `/settings/billing` redirects to `/billing`.

## Checkout / UI
Checkout and Customer Portal must use `Inertia::location($url)` so Inertia Form posts leave the app for Stripe (plain 302 is ignored). During trial, entitlements look like Basic but the user is not subscribed - show Start Basic and Upgrade to Pro, keyed off `subscribed`, not trial plan name.

## Stripe ops
Price IDs live in env (`STRIPE_PRICE_BASIC`, `STRIPE_PRICE_PRO`). Create them with `php artisan snitch:stripe-sync-plans`. Webhook path is `/stripe/webhook` (CSRF exempt). Never commit Stripe secrets.
