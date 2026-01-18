#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

CODE_SYNC="${SCRIPT_DIR}/brookstone-sync-code-from-live.sh"
DB_SYNC="${SCRIPT_DIR}/brookstone-sync-db-from-live.sh"

die() {
  echo "ERROR: $*" >&2
  exit 1
}

run_step() {
  local label="$1"
  local cmd="$2"

  echo
  echo ">>> ${label}"
  "$cmd"
}

# Validate scripts exist + executable
[[ -f "$CODE_SYNC" ]] || die "Missing: $CODE_SYNC"
[[ -f "$DB_SYNC"   ]] || die "Missing: $DB_SYNC"
[[ -x "$CODE_SYNC" ]] || die "Not executable: $CODE_SYNC (run: chmod +x \"$CODE_SYNC\")"
[[ -x "$DB_SYNC"   ]] || die "Not executable: $DB_SYNC (run: chmod +x \"$DB_SYNC\")"

# Nice failure message showing which command died
trap 'die "Failed at line $LINENO while running: ${BASH_COMMAND}"' ERR

run_step "Syncing code from LIVE..." "$CODE_SYNC"
run_step "Syncing database from LIVE..." "$DB_SYNC"

echo
echo ">>> Sync complete."
