#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-snitch}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env}"
APP_IMAGE="${APP_IMAGE:-ghcr.io/tmwclaxton/snitch:latest}"
# Host → GHCR egress is flaky (TLS handshake timeouts / hung blob downloads).
# CI should load the image over SSH from the Actions runner and set SKIP_GHCR_PULL=1.
# Keep timed registry pull as a manual / fallback path.
SKIP_GHCR_PULL="${SKIP_GHCR_PULL:-0}"
PULL_TIMEOUT_SECONDS="${PULL_TIMEOUT_SECONDS:-240}"
GHCR_MAX_ATTEMPTS="${GHCR_MAX_ATTEMPTS:-8}"
DEPLOY_LOCK_FILE="${DEPLOY_LOCK_FILE:-/tmp/snitch-production-deploy.lock}"
DEPLOY_SLOT_FILE="${DEPLOY_SLOT_FILE:-.deploy-slot}"
EDGE_DIR="${EDGE_DIR:-edge}"
EDGE_UPSTREAM_FILE="${EDGE_UPSTREAM_FILE:-${EDGE_DIR}/upstream-active.conf}"
EDGE_UPSTREAM_DEFAULT="${EDGE_UPSTREAM_DEFAULT:-docker/production/edge/upstream-active.conf.default}"
EDGE_IMAGE="${EDGE_IMAGE:-nginx:1.27-alpine}"
HEALTH_TIMEOUT_SECONDS="${HEALTH_TIMEOUT_SECONDS:-180}"

compose() {
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

app_service_for_slot() {
    case "$1" in
        blue) echo "app_blue" ;;
        green) echo "app_green" ;;
        *)
            echo "Unknown deploy slot: $1" >&2
            return 1
            ;;
    esac
}

container_name_for_slot() {
    case "$1" in
        blue) echo "snitch-app-blue" ;;
        green) echo "snitch-app-green" ;;
        *)
            echo "Unknown deploy slot: $1" >&2
            return 1
            ;;
    esac
}

inactive_slot() {
    if [ "$1" = "blue" ]; then
        echo "green"
    else
        echo "blue"
    fi
}

read_deploy_slot() {
    if [ -f "$DEPLOY_SLOT_FILE" ]; then
        local slot
        slot="$(tr -d '[:space:]' < "$DEPLOY_SLOT_FILE")"
        if [ "$slot" = "blue" ] || [ "$slot" = "green" ]; then
            echo "$slot"
            return 0
        fi
    fi

    echo "blue"
}

write_deploy_slot() {
    printf '%s\n' "$1" > "$DEPLOY_SLOT_FILE"
}

ensure_edge_upstream_file() {
    mkdir -p "$EDGE_DIR"
    if [ ! -f "$EDGE_UPSTREAM_FILE" ]; then
        cp "$EDGE_UPSTREAM_DEFAULT" "$EDGE_UPSTREAM_FILE"
    fi
}

write_upstream_for_slot() {
    local slot="$1"
    local container
    container="$(container_name_for_slot "$slot")"
    mkdir -p "$EDGE_DIR"
    printf 'server %s:80 max_fails=0;\n' "$container" > "$EDGE_UPSTREAM_FILE"
}

reload_edge_proxy() {
    local edge_id
    edge_id="$(compose ps -q edge || true)"
    if [ -z "$edge_id" ]; then
        echo "Edge proxy container is not running." >&2
        return 1
    fi

    docker exec "$edge_id" nginx -s reload
}

wait_for_container_healthy() {
    local container_id="$1"
    local deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))

    echo "Waiting for container ${container_id} to become healthy..."
    while [ "$SECONDS" -lt "$deadline" ]; do
        local status
        status="$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}unknown{{end}}' "$container_id" 2>/dev/null || echo missing)"
        if [ "$status" = "healthy" ]; then
            return 0
        fi
        if [ "$status" = "unhealthy" ]; then
            echo "Container ${container_id} reported unhealthy." >&2
            docker logs --tail 80 "$container_id" >&2 || true
            return 1
        fi
        sleep 3
    done

    echo "Timed out waiting for container ${container_id} to become healthy." >&2
    docker logs --tail 80 "$container_id" >&2 || true
    return 1
}

stop_app_workers() {
    local service="$1"
    if [ -z "$(compose ps -q "$service" || true)" ]; then
        return 0
    fi

    echo "Stopping queue workers on ${service}..."
    compose exec -T "$service" supervisorctl stop queue-worker:* scheduler 2>/dev/null || true
}

migrate_and_seed() {
    local service="$1"

    echo "Running migrations on ${service}..."
    compose exec -T "$service" php artisan migrate --force

    echo "Syncing analysis term catalogue on ${service}..."
    compose exec -T "$service" php artisan db:seed --class=AnalysisTermSeeder --force

    echo "Ensuring public storage link on ${service}..."
    compose exec -T "$service" php artisan storage:link --force --no-interaction 2>/dev/null \
        || compose exec -T "$service" php artisan storage:link --no-interaction 2>/dev/null \
        || true
}

ensure_edge_image() {
    if docker image inspect "$EDGE_IMAGE" >/dev/null 2>&1; then
        echo "Edge image ${EDGE_IMAGE} already present."
        return 0
    fi

    echo "Pulling edge image (${EDGE_IMAGE})..."
    retry_with_backoff 5 docker pull "$EDGE_IMAGE"
}

start_edge_proxy() {
    ensure_edge_image
    compose up -d --no-deps --pull never --force-recreate edge
}

retire_legacy_single_app_container() {
    if [ -n "$(compose ps -q app 2>/dev/null || true)" ]; then
        echo "Retiring legacy compose app service (one-time cutover)..."
        compose stop app 2>/dev/null || true
        compose rm -f app 2>/dev/null || true
    fi

    if docker ps -aq -f name='^/snitch-app-1$' | grep -q .; then
        echo "Retiring legacy snitch-app-1 container (one-time cutover)..."
        docker rm -f snitch-app-1 2>/dev/null || true
    fi
}

zero_downtime_deploy_app() {
    local active_slot
    local inactive_slot_name
    local active_service
    local inactive_service

    active_slot="$(read_deploy_slot)"
    inactive_slot_name="$(inactive_slot "$active_slot")"
    active_service="$(app_service_for_slot "$active_slot")"
    inactive_service="$(app_service_for_slot "$inactive_slot_name")"

    echo "Active slot: ${active_slot} (${active_service})"
    echo "Deploying inactive slot: ${inactive_slot_name} (${inactive_service})"

    retire_legacy_single_app_container
    ensure_edge_upstream_file

    echo "Ensuring postgres and redis are up..."
    compose up -d postgres redis

    if [ -z "$(compose ps -q "$active_service" || true)" ]; then
        echo "No active app slot running; bootstrapping ${active_service}..."
        compose up -d --no-deps --pull never "$active_service"
        wait_for_container_healthy "$(compose ps -q "$active_service")"
        migrate_and_seed "$active_service"
        write_upstream_for_slot "$active_slot"
        start_edge_proxy
        write_deploy_slot "$active_slot"
        echo "Bootstrap complete. Live slot: ${active_slot}"
        return 0
    fi

    start_edge_proxy

    stop_app_workers "$active_service"

    echo "Starting ${inactive_service} with the new image..."
    compose up -d --no-deps --pull never "$inactive_service"
    wait_for_container_healthy "$(compose ps -q "$inactive_service")"

    migrate_and_seed "$inactive_service"

    echo "Switching edge proxy to ${inactive_slot_name}..."
    write_upstream_for_slot "$inactive_slot_name"
    reload_edge_proxy

    echo "Stopping retired slot ${active_service}..."
    compose stop "$active_service"

    write_deploy_slot "$inactive_slot_name"
    echo "Zero-downtime app deploy complete. Live slot: ${inactive_slot_name}"
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
    timeout "${PULL_TIMEOUT_SECONDS}" docker pull "$APP_IMAGE"
}

exec 9>"$DEPLOY_LOCK_FILE"
if ! flock -n 9; then
    echo "Another production deploy is already running (lock: ${DEPLOY_LOCK_FILE})." >&2
    exit 1
fi

if [ "$SKIP_GHCR_PULL" = "1" ]; then
    echo "Skipping GHCR login/pull (image preloaded onto the host)."
else
    if [ -z "${GHCR_TOKEN:-}" ] || [ -z "${GHCR_ACTOR:-}" ]; then
        echo "GHCR_TOKEN and GHCR_ACTOR are required unless SKIP_GHCR_PULL=1." >&2
        exit 1
    fi

    echo "Preferring IPv4 for registry egress..."
    prefer_ipv4_egress

    echo "Logging in to GHCR..."
    retry_with_backoff "$GHCR_MAX_ATTEMPTS" ghcr_login

    echo "Pulling app image (${APP_IMAGE})..."
    retry_with_backoff "$GHCR_MAX_ATTEMPTS" pull_app_image
fi

zero_downtime_deploy_app

docker image prune -af
docker builder prune -af

echo "Deploy complete."
