# Production URL (HTTPS + www)

Canonical production URL: `https://www.snitchsocial.net`.

- `APP_URL` and OAuth redirect URIs must use `https://www.snitchsocial.net`
- Cloudflare enforces HTTP → HTTPS (Always Use HTTPS) and apex → www (301 Single Redirect)
- `SESSION_DOMAIN=.snitchsocial.net` covers both hosts
- Do not flip to apex-canonical without updating env, WorkOS, and the Cloudflare redirect together
