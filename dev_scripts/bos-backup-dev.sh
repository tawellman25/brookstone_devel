#!/usr/bin/env bash
set -euo pipefail

SRC="$HOME/code/brookstone"
DEST_BASE="/mnt/d/Backups/brookstone-dev"
STAMP="$(date +%Y%m%d_%H%M%S)"
DEST="${DEST_BASE}/dev-${STAMP}"

echo "Backing up DEV..."
echo "Source: $SRC"
echo "Target: $DEST"
echo

mkdir -p "$DEST"

rsync -av \
  --delete \
  --human-readable \
  --progress \
  --exclude ".git/" \
  --exclude "vendor/" \
  --exclude "node_modules/" \
  --exclude ".ddev/" \
  --exclude "web/sites/*/files/" \
  --exclude "web/sites/*/private/" \
  "$SRC/" "$DEST/"

echo
echo "Backup complete:"
echo "$DEST"

