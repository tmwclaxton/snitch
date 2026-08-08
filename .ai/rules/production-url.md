# Production URL (HTTPS + www)

Canonical production URL: `https://www.snitchsocial.net`.

- `APP_URL` and OAuth redirect URIs must use `https://www.snitchsocial.net`
- Cloudflare enforces HTTP → HTTPS (Always Use HTTPS) and apex → www (301 Single Redirect)
- `SESSION_DOMAIN=.snitchsocial.net` covers both hosts
- Do not flip to apex-canonical without updating env, WorkOS, and the Cloudflare redirect together

## Wayfinder asset builds must stay path-only

`URL::forceRootUrl(config('app.url'))` makes Wayfinder emit absolute URLs. Docker builds copy `.env.example` (`APP_URL=http://localhost`) before `wayfinder:generate` / `npm run build`, which previously baked `http://localhost/...` into production JS (dashboard/nav links).

- Skip `forceRootUrl` when argv is `wayfinder:generate` (see `AppServiceProvider`)
- Keep `APP_URL=` blank in the Docker build `.env` before Wayfinder/Vite
- Frontend helpers should be `/feed`-style paths, not host-qualified
- Runtime server URLs still use production `APP_URL` via `forceRootUrl`
- Cover with a real `php artisan wayfinder:generate` subprocess test (not in-process artisan)
