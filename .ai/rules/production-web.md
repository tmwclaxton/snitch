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
Docker bridges and the VPS host often lack working IPv6 egress. WorkOS DNS returns A+AAAA; without `/etc/gai.conf` preferring IPv4 (`precedence :ffff:0:0/96  100`), JWKS and refresh-token calls hang ~60s. The same broken AAAA path breaks GHCR / GitHub package blob pulls (`TLS handshake timeout`) during `Production Deploy` - `scripts/deploy-production.sh` must prefer IPv4 before `docker pull`, serialize with a flock, and the workflow must use `concurrency: production-deploy` so overlapping main pushes do not pull in parallel.

## Outbound HTTP
`Http::globalOptions` forces `CURLOPT_IPRESOLVE_V4` and a short `connect_timeout`. The WorkOS PHP SDK uses its own curl client - register `App\Support\WorkOs\Ipv4CurlRequestClient` via `WorkOS\Client::setRequestClient()` so token refresh cannot hang on IPv6. Container start warms `workos:jwk` via `snitch:warm-workos-jwk`.
