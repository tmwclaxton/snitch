---
paths:
  - 'bootstrap/app.php'
  - 'app/Providers/AppServiceProvider.php'
  - 'app/Services/Apify/**'
  - 'app/Jobs/SyncTrackedAccountJob.php'
  - 'app/Support/ClientIp.php'
  - 'app/Support/SafeExceptionMessage.php'
---

# Proxy trust and secret handling

## Never trust X-Forwarded-Host
`trustProxies` must omit `HEADER_X_FORWARDED_HOST`. Absolute URLs use `URL::forceRootUrl(config('app.url'))`. Spoofed forwarded Host previously rewrote redirects, sitemap locs, and Vite asset URLs on production.

## Guest throttles use CF-Connecting-IP
Contact rate limiting uses the `contact` limiter keyed by `ClientIp` (prefer `CF-Connecting-IP`). Do not rely on spoofable `X-Forwarded-For` alone for guest throttles.

## Apify auth is Bearer header only
`ApifyClient` and `TikHubClient` must send `Authorization: Bearer` via `withToken()`. Never put `APIFY_TOKEN` or `TIKHUB_API_KEY` in query strings or commit them. Persist sync failures with `SafeExceptionMessage::forUsers()` so tokens cannot appear in `last_sync_error`.
