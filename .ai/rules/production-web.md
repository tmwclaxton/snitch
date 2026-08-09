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

## IPv4 preference
Docker bridges and the VPS host often lack working IPv6 egress. WorkOS DNS returns A+AAAA; without `/etc/gai.conf` preferring IPv4 (`precedence :ffff:0:0/96  100`), JWKS and refresh-token calls hang ~60s. The same broken AAAA path breaks host GHCR / GitHub package blob pulls (`TLS handshake timeout`) during `Production Deploy`.

## Production image delivery
CI must not rely on the VPS pulling from GHCR. Pull the sha-tagged image on the Actions runner, stream `docker save | gzip` over SSH into `docker load`, tag `:latest`, then run `SKIP_GHCR_PULL=1 ./deploy-production.sh` with `compose up -d --pull never`. Keep timed host registry pull (with IPv4 preference + flock) only as a manual fallback. Workflow `concurrency: production-deploy` (`cancel-in-progress: false`) serializes overlapping main pushes.

## Outbound HTTP
`Http::globalOptions` forces `CURLOPT_IPRESOLVE_V4` and a short `connect_timeout`. The WorkOS PHP SDK uses its own curl client - register `App\Support\WorkOs\Ipv4CurlRequestClient` via `WorkOS\Client::setRequestClient()` so token refresh cannot hang on IPv6. Container start warms `workos:jwk` via `snitch:warm-workos-jwk`.
