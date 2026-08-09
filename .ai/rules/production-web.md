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
CI must not rely on the VPS pulling from GHCR. Pull the sha-tagged image on the Actions runner, stream `docker save | gzip` over SSH into `docker load`, tag `:latest`, then run `SKIP_GHCR_PULL=1 ./deploy-production.sh` with `compose up -d --pull never`. Keep timed host registry pull (with IPv4 preference + flock) only as a manual fallback. Workflow `concurrency: production-deploy` (`cancel-in-progress: false`) serializes overlapping main pushes.

## Cloudflare Access SSH
Deploy uses `cloudflared access ssh` with a service token. Fresh ProxyCommand handshakes can flap as `Connection timed out during banner exchange` / `UNKNOWN port 65535`. Open one SSH `ControlMaster` (IPv4 / absolute `cloudflared` path) before scp/image transfer/deploy so later steps reuse the socket, and copy compose + deploy script in a single `scp`.

## Image size
Do not `COPY` host `storage/` into the image (local `inertia-devtools` / logs can be hundreds of MB). Keep a root `.dockerignore` that excludes `.git`, `node_modules`, `vendor`, and `storage/inertia-devtools`. Create empty runtime storage dirs in the Dockerfile instead.
