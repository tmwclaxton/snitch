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
Docker bridges often lack working IPv6 egress. WorkOS DNS returns A+AAAA; without `/etc/gai.conf` preferring IPv4 (`precedence :ffff:0:0/96  100`), JWKS and refresh-token calls hang ~60s.

## Image size
Do not `COPY` host `storage/` into the image (local `inertia-devtools` / logs can be hundreds of MB). Keep a root `.dockerignore` that excludes `.git`, `node_modules`, `vendor`, and `storage/inertia-devtools`. Create empty runtime storage dirs in the Dockerfile instead.
