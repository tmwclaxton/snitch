#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-snitch}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env}"
APP_IMAGE="${APP_IMAGE:-ghcr.io/tmwclaxton/snitch:latest}"
# GHCR pulls from the self-hosted host can hit transient TLS / connection resets.
# Keep each attempt bounded so a hung blob download cannot sit for tens of minutes.
PULL_TIMEOUT_SECONDS="${PULL_TIMEOUT_SECONDS:-240}"
GHCR_MAX_ATTEMPTS="${GHCR_MAX_ATTEMPTS:-8}"
DEPLOY_LOCK_FILE="${DEPLOY_LOCK_FILE:-/tmp/snitch-production-deploy.lock}"

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

# Prefer IPv4 when DNS returns A+AAAA. The production host often has broken IPv6
# egress; GHCR / GitHub package blob TLS handshakes hang or reset on AAAA paths.
prefer_ipv4_egress() {
    local gai_line='precedence :ffff:0:0/96  100'
    local gai_file=/etc/gai.conf

    if [ -f "$gai_file" ] && grep -Fq 'precedence :ffff:0:0/96' "$gai_file" 2>/dev/null; then
        echo "Host already prefers IPv4 via ${gai_file}."
        return 0
    fi

    if [ -w "$gai_file" ] 2>/dev/null || [ -w "$(dirname "$gai_file")" ]; then
        printf '%s\n' "$gai_line" >> "$gai_file"
        echo "Updated ${gai_file} to prefer IPv4 egress."
        return 0
    fi

    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        printf '%s\n' "$gai_line" | sudo tee -a "$gai_file" >/dev/null
        echo "Updated ${gai_file} to prefer IPv4 egress (via sudo)."
        return 0
    fi

    echo "Warning: could not write ${gai_file}; GHCR pulls may still hit broken IPv6." >&2
}

ghcr_login() {
    echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_ACTOR" --password-stdin
}

pull_app_image() {
    # Fresh login each attempt: long hung pulls can leave docker credentials stale.
    ghcr_login

    # Bound hung blob downloads so we can retry instead of sitting forever.
    # Pull the tagged image directly (same ref compose uses) so retries do not
    # depend on compose project state from a previous timed-out pull.
    timeout "${PULL_TIMEOUT_SECONDS}" docker pull "$APP_IMAGE"
}

if [ -z "${GHCR_TOKEN:-}" ] || [ -z "${GHCR_ACTOR:-}" ]; then
    echo "GHCR_TOKEN and GHCR_ACTOR are required." >&2
    exit 1
fi

exec 9>"$DEPLOY_LOCK_FILE"
if ! flock -n 9; then
    echo "Another production deploy is already running (lock: ${DEPLOY_LOCK_FILE})." >&2
    exit 1
fi

echo "Preferring IPv4 for registry egress..."
prefer_ipv4_egress

echo "Logging in to GHCR..."
retry_with_backoff "$GHCR_MAX_ATTEMPTS" ghcr_login

echo "Pulling app image (${APP_IMAGE})..."
retry_with_backoff "$GHCR_MAX_ATTEMPTS" pull_app_image

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
