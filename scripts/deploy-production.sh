#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-snitch}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env}"

compose() {
    docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

echo "Logging in to GHCR..."
echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_ACTOR" --password-stdin

echo "Pulling app image..."
compose pull app

echo "Starting / updating stack..."
compose up -d

echo "Running migrations..."
compose exec -T app php artisan migrate --force

echo "Ensuring public storage link..."
compose exec -T app php artisan storage:link --force --no-interaction 2>/dev/null || compose exec -T app php artisan storage:link --no-interaction 2>/dev/null || true

docker image prune -af
docker builder prune -af

echo "Deploy complete."
