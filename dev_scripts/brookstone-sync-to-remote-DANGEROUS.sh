#!/usr/bin/env bash
set -euo pipefail
export LANG=C
export LC_ALL=C

###############################################################################
# sync-to-remote-DANGEROUS.sh
# LIVE deploy script — rsync code only, composer install, config import.
# SAFE DEFAULT: DRY-RUN + explicit confirmation required.
#
# NOTE:
# - .vscode/, dev_scripts/, __BOS_AI/ are local-only and excluded from sync.
#   They will not be sent to LIVE and will not be deleted from LIVE.
###############################################################################

REMOTE_HOST="brookstone"
REMOTE_ROOT="/home/brookstoneadmin/brookstone"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

DRY_RUN=1
REQUIRE_CONFIRM=1
USE_MAINTENANCE=1
RUN_COMPOSER=1
RUN_CIM=1
RUN_CR=1

LOCK_NAME=".deploy-lock-brookstone"
RSYNC_TIMEOUT=60
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=10)

timestamp() { date +"%Y-%m-%d %H:%M:%S"; }
log() { printf "[%s] %s\n" "$(timestamp)" "$*"; }
die() { log "ERROR: $*"; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --live) DRY_RUN=0 ;;
    --dry-run) DRY_RUN=1 ;;
    --yes) REQUIRE_CONFIRM=0 ;;
    --no-maintenance) USE_MAINTENANCE=0 ;;
    --skip-composer) RUN_COMPOSER=0 ;;
    --skip-cim) RUN_CIM=0 ;;
    --skip-cr) RUN_CR=0 ;;
    *) die "Unknown option: $1" ;;
  esac
  shift
done

remote() {
  ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "bash -lc '$*'"
}

cleanup() {
  local code=$?
  if [[ $code -ne 0 ]]; then
    log "DEPLOY FAILED — leaving LIVE in maintenance mode."
  else
    [[ "$USE_MAINTENANCE" -eq 1 ]] && remote "cd '${REMOTE_ROOT}' && drush sset system.maintenance_mode 0 -y && drush cr -y"
  fi
  remote "cd '${REMOTE_ROOT}' && rm -f '${LOCK_NAME}'" || true
  exit $code
}
trap cleanup EXIT

log "Preflight checks…"
command -v rsync >/dev/null 2>&1 || die "rsync not found"
[[ -d "$LOCAL_ROOT" ]] || die "LOCAL_ROOT not found: $LOCAL_ROOT"
ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "true" >/dev/null
remote "cd '${REMOTE_ROOT}' >/dev/null"
remote "command -v drush >/dev/null"
[[ "$RUN_COMPOSER" -eq 1 ]] && remote "command -v composer >/dev/null"
remote "grep -q 'brookstoneadmin_bos_prod' '${REMOTE_ROOT}/web/sites/default/settings.php'" \
  || die "ABORT: Remote settings.php does not contain expected database name. Refusing to deploy. Fix settings.php before deploying."

if [[ "$DRY_RUN" -eq 0 && "$REQUIRE_CONFIRM" -eq 1 ]]; then
  log "LIVE DEPLOY — type LIVE to continue:"
  read -r confirm
  [[ "$confirm" == "LIVE" ]] || die "Aborted"
fi

log "Acquiring deploy lock…"
remote "cd '${REMOTE_ROOT}' && test ! -e '${LOCK_NAME}' && echo 'locked' > '${LOCK_NAME}'"

if [[ "$DRY_RUN" -eq 0 && "$USE_MAINTENANCE" -eq 1 ]]; then
  log "Enabling maintenance mode…"
  remote "cd '${REMOTE_ROOT}' && drush sset system.maintenance_mode 1 -y && drush cr -y"
fi

log "Syncing code to LIVE…"

RSYNC_FLAGS=(
  -avz
  --delete
  --timeout="${RSYNC_TIMEOUT}"
  --no-perms --no-owner --no-group --no-times
  --human-readable
  --itemize-changes
  --delay-updates
  --partial
  --safe-links

  # local-only dev directories — exclude from sync entirely (not sent, not deleted on LIVE)
  --exclude ".vscode/"
  --exclude "dev_scripts/"
  --exclude "__BOS_AI/"

  # deps/local-only
  --exclude "vendor/"
  --exclude ".git/"
  --exclude "node_modules/"
  --exclude ".ddev/"
  --exclude ".env"
  --exclude ".env.*"
  --exclude ".idea/"
  --exclude "__pycache__/"
  --exclude "Thumbs.db"
  --exclude ".DS_Store"
  --exclude "*.log"
  --exclude "*.swp"
  --exclude "*.bak"

  # Drupal core/contrib/files
  --exclude "web/core/"
  --exclude "web/modules/contrib/"
  --exclude "web/themes/contrib/"
  --exclude "web/profiles/"
  --exclude "web/sites/*/files/"
  --exclude "web/sites/default/settings.php"
  --exclude "web/sites/default/settings.local.php"
  --exclude "web/sites/*/settings.php"
  --exclude "web/sites/*/settings.local.php"
  --exclude "web/sites/default/settings.ddev.php"
  --exclude "web/sites/default/.gitignore"
  --exclude "web/s3_uri_verification.txt"

  # AWS clutter variants
  --exclude "aws/"
  --exclude "aws-sdk-php/"
  --exclude "aws_sdk/"
  --exclude "web/libraries/aws/"
  --exclude "web/libraries/aws-sdk-php/"
  --exclude "web/libraries/aws_sdk/"
  --exclude "web/modules/custom/*/aws/"
  --exclude "web/modules/custom/*/aws-sdk-php/"
  --exclude "web/modules/custom/*/aws_sdk/"

  # dumps
  --exclude "*.sql"
  --exclude "*.sql.gz"
)

[[ "$DRY_RUN" -eq 1 ]] && RSYNC_FLAGS+=(--dry-run)

rsync "${RSYNC_FLAGS[@]}" \
  -e "ssh ${SSH_OPTS[*]}" \
  "${LOCAL_ROOT}/" \
  "${REMOTE_HOST}:${REMOTE_ROOT}/"

if [[ "$DRY_RUN" -eq 1 ]]; then
  log "DRY-RUN complete — no remote changes made."
  exit 0
fi

[[ "$RUN_COMPOSER" -eq 1 ]] && remote "cd '${REMOTE_ROOT}' && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-req=ext-redis"
[[ "$RUN_CIM" -eq 1 ]] && remote "cd '${REMOTE_ROOT}' && drush cim -y"
[[ "$RUN_CR" -eq 1 ]] && remote "cd '${REMOTE_ROOT}' && drush cr -y"

log "Deploy complete (code + vendor + config; DB untouched; files on S3)."