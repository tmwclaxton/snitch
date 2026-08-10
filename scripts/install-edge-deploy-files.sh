#!/usr/bin/env bash
# Install staged edge nginx configs into bind-mount paths under DEPLOY_DIR.
# Called on the production host after CI scps files into .deploy-staging/.
#
# scp cannot truncate root-owned files Docker may leave when a file bind source
# was missing (or became a root-owned directory). Prefer unlink+cp; fall back to
# rewriting via the local docker daemon using the preloaded edge image.
set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-/opt/snitch}"
EDGE_IMAGE="${EDGE_IMAGE:-nginx:1.27-alpine}"
STAGE="${DEPLOY_DIR}/.deploy-staging"
EDGE="${DEPLOY_DIR}/docker/production/edge"

install_edge_file() {
    local src="$1"
    local dest="$2"
    local dest_dir dest_base
    dest_dir="$(dirname "$dest")"
    dest_base="$(basename "$dest")"
    mkdir -p "$dest_dir"

    if [ -d "$dest" ] && [ ! -L "$dest" ]; then
        rm -rf "$dest" || true
    elif [ -e "$dest" ]; then
        rm -f "$dest" || true
    fi

    if cp "$src" "$dest" 2>/dev/null; then
        chmod 644 "$dest"
        return 0
    fi

    echo "Direct install failed for ${dest}; rewriting via docker (${EDGE_IMAGE})..." >&2
    ls -la "$dest_dir" "$dest" 2>/dev/null || true
    docker image inspect "$EDGE_IMAGE" >/dev/null 2>&1 || {
        echo "Edge image ${EDGE_IMAGE} missing on host; cannot rewrite ${dest}" >&2
        return 1
    }
    docker run --rm \
        -v "${dest_dir}:/out" \
        -v "${src}:/in/file:ro" \
        "$EDGE_IMAGE" \
        sh -c "rm -rf \"/out/${dest_base}\" && cp /in/file \"/out/${dest_base}\" && chmod 644 \"/out/${dest_base}\""
}

ls -la "$EDGE" "$EDGE/conf.d" "$STAGE" || true
install_edge_file "$STAGE/nginx.conf" "$EDGE/nginx.conf"
install_edge_file "$STAGE/upstream-active.conf.default" "$EDGE/upstream-active.conf.default"
install_edge_file "$STAGE/snitch.conf" "$EDGE/conf.d/snitch.conf"
