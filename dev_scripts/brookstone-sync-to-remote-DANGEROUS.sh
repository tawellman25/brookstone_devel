#!/usr/bin/env bash
set -euo pipefail
export LANG=C
export LC_ALL=C

###############################################################################
# sync-to-remote-DANGEROUS.sh
# LIVE deploy script — rsync code, composer install, drush cr.
# SAFE DEFAULT: DRY-RUN + explicit confirmation required.
#
# Usage:
#   --live              Execute a real deploy (default: dry-run)
#   --dry-run           Force dry-run (default)
#   --yes               Skip confirmation prompt
#   --cim               Run drush cim after composer install (opt-in)
#   --no-maintenance    Skip maintenance mode
#   --skip-composer     Skip composer install
#   --skip-cr           Skip drush cr
#
# NOTE:
# - Config import (drush cim) does NOT run by default. Pass --cim to enable.
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
RUN_CIM=0
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
    --cim) RUN_CIM=1 ;;
    --skip-cr) RUN_CR=0 ;;
    *) die "Unknown option: $1" ;;
  esac
  shift
done

remote() {
  ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "bash -lc '$*'"
}

# Run a multi-line bash script on the remote in a SINGLE ssh session.
# Stdin is piped through to `bash -ls` on the remote so the whole
# heredoc runs in one connection. Use this whenever you have 2+ remote
# commands to issue — it avoids the SSH-burst pattern that previously
# tripped the live host's MaxStartups / fail2ban rate limiter (the
# preflight phase alone used to open ~5 SSH sessions in <2 seconds,
# which the live host rejected with "Connection reset by peer" and
# left the deploy in a partial state).
#
# Usage:
#   remote_script <<EOF
#     set -e
#     cd "${REMOTE_ROOT}"
#     drush cr
#   EOF
#
# Variables inside the heredoc are expanded LOCALLY before the script
# is sent (unquoted EOF marker), so ${REMOTE_ROOT}, $RUN_CIM, etc.
# reach the remote already substituted. Use 'EOF' (quoted) if you ever
# need a literal $ on the remote side.
remote_script() {
  ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "bash -ls"
}

cleanup() {
  local code=$?
  # Compose maintenance-off + lock-remove into ONE ssh session. On
  # failure we leave maintenance on (intentional fail-safe) but still
  # remove the lock so the next deploy can re-acquire it.
  local cleanup_script
  if [[ $code -ne 0 ]]; then
    log "DEPLOY FAILED — leaving LIVE in maintenance mode."
    cleanup_script="cd '${REMOTE_ROOT}' && rm -f '${LOCK_NAME}'"
  elif [[ "$USE_MAINTENANCE" -eq 1 ]]; then
    cleanup_script="cd '${REMOTE_ROOT}' && drush sset system.maintenance_mode 0 -y && drush cr -y && rm -f '${LOCK_NAME}'"
  else
    cleanup_script="cd '${REMOTE_ROOT}' && rm -f '${LOCK_NAME}'"
  fi
  remote_script <<EOF || true
${cleanup_script}
EOF
  exit $code
}
trap cleanup EXIT

log "Preflight checks…"
command -v rsync >/dev/null 2>&1 || die "rsync not found"
[[ -d "$LOCAL_ROOT" ]] || die "LOCAL_ROOT not found: $LOCAL_ROOT"

# Consolidated remote preflight — single SSH session. The previous
# pattern of 4-5 separate `remote` calls in a row tripped the live
# host's SSH rate limiter, producing "Connection reset by peer" and
# aborting mid-deploy with maintenance mode stuck on. Now: one
# session, all checks, fail with a specific message identifying
# which check rejected.
remote_script <<EOF || die "Remote preflight failed (see error above)."
set -e
cd "${REMOTE_ROOT}" || { echo "FAIL: cannot cd to ${REMOTE_ROOT}" >&2; exit 1; }
command -v drush >/dev/null || { echo "FAIL: drush not found on remote PATH" >&2; exit 1; }
$( [[ "$RUN_COMPOSER" -eq 1 ]] && echo 'command -v composer >/dev/null || { echo "FAIL: composer not found on remote PATH" >&2; exit 1; }' )
grep -q 'brookstoneadmin_bos_prod' web/sites/default/settings.php \
  || { echo "FAIL: web/sites/default/settings.php does not contain expected database name 'brookstoneadmin_bos_prod'. Refusing to deploy." >&2; exit 1; }
EOF

if [[ "$DRY_RUN" -eq 0 && "$REQUIRE_CONFIRM" -eq 1 ]]; then
  log "LIVE DEPLOY — type LIVE to continue:"
  read -r confirm
  [[ "$confirm" == "LIVE" ]] || die "Aborted"
fi

# Combined lock acquire + maintenance enable in ONE ssh session.
# Lock first so a race between two concurrent deploys can't both
# enable maintenance.
log "Acquiring deploy lock${DRY_RUN:+ (dry-run skips maintenance)}…"
if [[ "$DRY_RUN" -eq 0 && "$USE_MAINTENANCE" -eq 1 ]]; then
  remote_script <<EOF
set -e
cd "${REMOTE_ROOT}"
test ! -e "${LOCK_NAME}" || { echo "FAIL: deploy lock '${LOCK_NAME}' already exists — another deploy may be in progress, or the previous deploy's cleanup didn't finish. Remove it manually if you've verified no other deploy is running." >&2; exit 1; }
echo 'locked' > "${LOCK_NAME}"
drush sset system.maintenance_mode 1 -y
drush cr -y
EOF
else
  remote_script <<EOF
set -e
cd "${REMOTE_ROOT}"
test ! -e "${LOCK_NAME}" || { echo "FAIL: deploy lock '${LOCK_NAME}' already exists." >&2; exit 1; }
echo 'locked' > "${LOCK_NAME}"
EOF
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
  --exclude "TimeTrax_Hack/"

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

# Post-rsync remote work — composer install + (optional) cim + drush cr,
# all in ONE ssh session. Previously three sequential `remote` calls;
# the trailing two would occasionally hit the rate limiter mid-deploy.
post_rsync_script="set -e
cd '${REMOTE_ROOT}'
"
[[ "$RUN_COMPOSER" -eq 1 ]] && post_rsync_script+="composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-req=ext-redis
"
[[ "$RUN_CIM" -eq 1 ]] && post_rsync_script+="drush cim -y
"
[[ "$RUN_CR" -eq 1 ]] && post_rsync_script+="drush cr -y
"
remote_script <<EOF
${post_rsync_script}
EOF

log "Deploy complete (code + vendor${RUN_CIM:+, config import}; DB untouched; files on S3)."