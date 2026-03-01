#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "$SCRIPT_DIR/.." && pwd)"
DEST_BASE="/mnt/d/Backups/brookstone-dev"
STAMP="$(date +%Y%m%d_%H%M%S)"
DEST="${DEST_BASE}/dev-${STAMP}"

command -v rsync >/dev/null 2>&1 || { echo "ERROR: rsync not found"; exit 1; }

if [[ ! -d "$DEST_BASE" ]]; then
  echo "ERROR: Backup destination not found: $DEST_BASE"
  echo "       Is /mnt/d mounted?"
  exit 1
fi

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
