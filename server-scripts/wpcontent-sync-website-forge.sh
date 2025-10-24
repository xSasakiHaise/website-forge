#!/usr/bin/env bash
set -euo pipefail

REPO_OWNER="xSasakiHaise"
REPO_NAME="website-forge"
BRANCH="main"
TARGET="/mnt/.ix-apps/app_mounts/wordpress-forge/data/wp-content"

WORKDIR="$(mktemp -d)"
TARBALL="${WORKDIR}/${REPO_NAME}.tar.gz"
EXTRACT="${WORKDIR}/extract"
cleanup(){ rm -rf "$WORKDIR"; }
trap cleanup EXIT

if [ ! -s /root/.github_token_website_forge ]; then
  echo "ERROR: /root/.github_token_website_forge missing or empty." >&2
  exit 1
fi
TOKEN="$(cat /root/.github_token_website_forge)"

curl -fsSL -H "Authorization: token ${TOKEN}" \
  "https://api.github.com/repos/${REPO_OWNER}/${REPO_NAME}/tarball/${BRANCH}" \
  -o "$TARBALL"

mkdir -p "$EXTRACT"
tar -xzf "$TARBALL" -C "$EXTRACT"

SRC="$(find "$EXTRACT" -mindepth 1 -maxdepth 1 -type d | head -n1)"
if [ ! -d "${SRC}/wp-content" ]; then
  echo "No wp-content/ in repo; nothing to sync." >&2
  exit 0
fi

rsync -az --delete \
  --exclude 'uploads/' \
  --exclude '.git/' \
  "${SRC}/wp-content/" "${TARGET}/"

if command -v docker >/dev/null 2>&1 && \
   docker ps --format '{{.Names}}' | grep -q '^ix-wordpress-forge-wordpress-1$'; then
  docker exec -i ix-wordpress-forge-wordpress-1 wp cache flush || true
  docker exec -i ix-wordpress-forge-wordpress-1 wp rewrite flush --hard || true
fi

echo "Sync complete -> ${TARGET}"
