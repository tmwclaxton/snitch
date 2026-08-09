#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-snitch}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env}"
# Host → GHCR egress is flaky (TLS handshake timeouts / hung blob downloads).
# CI should load the image over SSH from the Actions runner and set SKIP_GHCR_PULL=1.
# Keep timed registry pull as a manual / fallback path.
SKIP_GHCR_PULL="${SKIP_GHCR_PULL:-0}"
PULL_TIMEOUT_SECONDS="${PULL_TIMEOUT_SECONDS:-180}"
GHCR_MAX_ATTEMPTS="${GHCR_MAX_ATTEMPTS:-6}"

compose() {
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

# Retry a command with exponential backoff (5s, 10s, 20s, ... capped at 60s).
retry_with_backoff() {
    local max_attempts="$1"
    shift
    local attempt=1
    local delay=5

    while [ "$attempt" -le "$max_attempts" ]; do
        if "$@"; then
            return 0
        fi

        if [ "$attempt" -eq "$max_attempts" ]; then
            echo "Command failed after ${max_attempts} attempts: $*" >&2
            return 1
        fi

        echo "Attempt ${attempt}/${max_attempts} failed; retrying in ${delay}s..."
        sleep "$delay"
        delay=$((delay * 2))
        if [ "$delay" -gt 60 ]; then
            delay=60
        fi
        attempt=$((attempt + 1))
    done
}

ghcr_login() {
    echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_ACTOR" --password-stdin
}

pull_app_image() {
    # Bound hung blob downloads so we can retry instead of sitting for tens of minutes.
    timeout "${PULL_TIMEOUT_SECONDS}" docker compose \
        -p "$COMPOSE_PROJECT" \
        -f "$COMPOSE_FILE" \
        --env-file "$ENV_FILE" \
        pull app
}

if [ "$SKIP_GHCR_PULL" = "1" ]; then
    echo "Skipping GHCR login/pull (image preloaded onto the host)."
else
    if [ -z "${GHCR_TOKEN:-}" ] || [ -z "${GHCR_ACTOR:-}" ]; then
        echo "GHCR_TOKEN and GHCR_ACTOR are required unless SKIP_GHCR_PULL=1." >&2
        exit 1
    fi

    echo "Logging in to GHCR..."
    retry_with_backoff "$GHCR_MAX_ATTEMPTS" ghcr_login

    echo "Pulling app image..."
    retry_with_backoff "$GHCR_MAX_ATTEMPTS" pull_app_image
fi

echo "Starting / updating stack..."
compose up -d --pull never

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
