#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-snitch}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env}"
# GHCR pulls from this host are flaky (TLS handshake timeouts / connection resets).
GHCR_RETRY_ATTEMPTS="${GHCR_RETRY_ATTEMPTS:-6}"
GHCR_RETRY_BASE_SECONDS="${GHCR_RETRY_BASE_SECONDS:-10}"

compose() {
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

# Retry with exponential backoff for transient registry / network failures.
retry_with_backoff() {
    local attempt=1
    local delay="$GHCR_RETRY_BASE_SECONDS"
    local max_attempts="$GHCR_RETRY_ATTEMPTS"

    while true; do
        if "$@"; then
            return 0
        fi

        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "Command failed after ${max_attempts} attempts: $*" >&2
            return 1
        fi

        echo "Attempt ${attempt}/${max_attempts} failed; retrying in ${delay}s..."
        sleep "$delay"
        attempt=$((attempt + 1))
        delay=$((delay * 2))
        if [ "$delay" -gt 120 ]; then
            delay=120
        fi
    done
}

ghcr_login() {
    echo "Logging in to GHCR..."
    echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_ACTOR" --password-stdin
}

echo "Authenticating with GHCR (retries on transient network errors)..."
retry_with_backoff ghcr_login

echo "Pulling app image..."
retry_with_backoff compose pull app

echo "Starting / updating stack..."
compose up -d

echo "Running migrations..."
compose exec -T app php artisan migrate --force

# Idempotent catalogue sync for Explore / analysis taxonomy filters.
echo "Syncing analysis term catalogue..."
compose exec -T app php artisan db:seed --class=AnalysisTermSeeder --force

echo "Ensuring public storage link..."
compose exec -T app php artisan storage:link --force --no-interaction 2>/dev/null || compose exec -T app php artisan storage:link --no-interaction 2>/dev/null || true

docker image prune -af
docker builder prune -af

echo "Deploy complete."
