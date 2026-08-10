---
paths:
  - 'Dockerfile'
  - 'docker/production/**'
  - 'app/Console/Commands/WarmWorkOsJwkCommand.php'
  - 'app/Support/WorkOs/**'
  - 'app/Providers/AppServiceProvider.php'
  - 'compose.prod.yaml'
---

# Production web stack and WorkOS egress

## Web server
Production image must serve via **nginx + php-fpm**, not `php artisan serve`. The built-in server is single-threaded; one slow authenticated request stalls the whole site (dashboard / competitors / brand).

Use `docker/production/supervisord.conf` (include workers + `web.conf` only). Do not copy Sail's `docker/8.5/supervisord.conf`, which requires `SUPERVISOR_PHP_COMMAND` for `artisan serve`. After deleting `www-data`, set nginx's main `user` to `sail` or nginx will fail with `getpwnam("www-data")`.

## IPv4 preference
Docker bridges and the VPS host often lack working IPv6 egress. WorkOS DNS returns A+AAAA; without `/etc/gai.conf` preferring IPv4 (`precedence :ffff:0:0/96  100`), JWKS and refresh-token calls hang ~60s. The same broken AAAA path breaks host GHCR / GitHub package blob pulls (`TLS handshake timeout`) during `Production Deploy`.

## Production image delivery
CI must not rely on the VPS pulling from GHCR. Pull the sha-tagged app image **and** edge `nginx:1.27-alpine` on the Actions runner, stream `docker save | gzip` over SSH into `docker load`, tag app `:latest`, then run `SKIP_GHCR_PULL=1 ./deploy-production.sh` with blue/green slot swap (`compose up -d --no-deps --pull never` on app slots and edge). `deploy-production.sh` also calls `ensure_edge_image` so a missing nginx image is pulled on the host as a fallback. Keep timed host GHCR pull (with IPv4 preference + flock) only as a manual fallback. Workflow `concurrency: production-deploy` (`cancel-in-progress: false`) serializes overlapping main pushes.

## Zero-downtime deploy (blue/green)
HTTP stays on **edge** nginx at host port **8095** (Cloudflare tunnel target). App runs in two slots: `app_blue` (`snitch-app-blue`) and `app_green` (`snitch-app-green`). Only one slot serves traffic at a time via `edge/upstream-active.conf` on the host.

Deploy flow (`scripts/deploy-production.sh`):
1. Stop queue workers on the live slot (avoids duplicate job processing while both containers exist).
2. Start the inactive slot with the new image and wait for `/up` health.
3. Run `migrate`, `AnalysisTermSeeder`, and `storage:link` on the candidate slot.
4. Rewrite `edge/upstream-active.conf`, `nginx -s reload` on edge.
5. Stop and `compose rm -f` the retired slot (keeps monitors from flagging an exited inactive container); flip `.deploy-slot` (`blue` or `green`).

Mount upstream at `/etc/nginx/snitch/upstream-active.conf` (not under `conf.d/`, or nginx parses it as a top-level server block).

Edge nginx must use enlarged proxy buffers (`proxy_buffer_size 128k`, `proxy_buffers 8 128k`) or Inertia/Laravel response headers cause `502 upstream sent too big header` on `/` while `/up` still returns 200.

Edge must set `X-Forwarded-Proto https` (not `$scheme`). The edge container listens on HTTP only; Cloudflare terminates TLS. Using `$scheme` made Laravel emit `http://` pagination and absolute URLs, so Inertia visits from the HTTPS site failed as mixed content. App-side, `Paginator::currentPathResolver` also emits path-only links as a second line of defense.

First deploy after this change retires legacy `app` / `snitch-app-1` if present (brief one-time cutover while port 8095 moves to edge). Copy edge configs in CI (`docker/production/edge/**`) and ensure `/opt/snitch/edge/upstream-active.conf` exists (seed from `upstream-active.conf.default`).

## Cloudflare Access SSH
Deploy uses `cloudflared access ssh` with a service token. Fresh ProxyCommand handshakes can flap as `Connection timed out during banner exchange` / `UNKNOWN port 65535`. Open one SSH `ControlMaster` (IPv4 / absolute `cloudflared` path) before scp/image transfer/deploy so later steps reuse the socket, and copy compose + deploy script in a single `scp`.

Before `scp`, the workflow must `rm -f` the destination deploy files under `/opt/snitch`. Directory mode lets `snitch` unlink, but overwriting a file owned by another UID (seen with `edge/conf.d/snitch.conf` owned by `autocvapply`) fails with `Permission denied` and aborts before image transfer.

## Image size
Do not `COPY` host `storage/` into the image (local `inertia-devtools` / logs can be hundreds of MB). Keep a root `.dockerignore` that excludes `.git`, `node_modules`, `vendor`, and `storage/inertia-devtools`. Create empty runtime storage dirs in the Dockerfile instead.
