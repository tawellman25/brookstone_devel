#!/usr/bin/env bash
set -euo pipefail

# ----------------------------
# Config
# ----------------------------
REMOTE_HOST="brookstone"
REMOTE_PROJECT_ROOT="/home/brookstoneadmin/brookstone"
REMOTE_WEB_ROOT="${REMOTE_PROJECT_ROOT}/web"
REMOTE_SITE_PATH="sites/default"
REMOTE_SETTINGS="${REMOTE_WEB_ROOT}/${REMOTE_SITE_PATH}/settings.php"
REMOTE_TMP_DIR="/home/brookstoneadmin/tmp"

# Which $databases key to dump: default (live) or seward7 (legacy)
REMOTE_DB_KEY="${REMOTE_DB_KEY:-default}"

KEEP_LOCAL_DUMP="${KEEP_LOCAL_DUMP:-0}"

# ----------------------------
# Local project root
# ----------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# ----------------------------
# Preflight (local)
# ----------------------------
command -v ssh   >/dev/null 2>&1 || { echo "ERROR: ssh not found";   exit 1; }
command -v rsync >/dev/null 2>&1 || { echo "ERROR: rsync not found"; exit 1; }
command -v ddev  >/dev/null 2>&1 || { echo "ERROR: ddev not found";  exit 1; }

# ----------------------------
# Disk space check (require 2GB free)
# ----------------------------
echo ">>> Checking local disk space…"
AVAILABLE=$(df -BG "$PROJECT_ROOT" | awk 'NR==2 {gsub("G","",$4); print $4}')
if [[ "$AVAILABLE" -lt 2 ]]; then
  echo "ERROR: Less than 2GB free locally — aborting"
  exit 1
fi

# ----------------------------
# Unique dump filename
# ----------------------------
STAMP="$(date +%Y%m%d-%H%M%S)"
DUMP_BASENAME="brookstone-sync-${REMOTE_DB_KEY}-${STAMP}.sql.gz"
LOCAL_DUMP="${PROJECT_ROOT}/${DUMP_BASENAME}"
REMOTE_DUMP="${REMOTE_TMP_DIR}/${DUMP_BASENAME}"

cleanup() {
  ssh "$REMOTE_HOST" "LANG=C LC_ALL=C rm -f '$REMOTE_DUMP'" >/dev/null 2>&1 || true
  if [[ "$KEEP_LOCAL_DUMP" != "1" ]]; then
    rm -f "$LOCAL_DUMP" >/dev/null 2>&1 || true
  else
    echo ">>> Keeping local dump: $LOCAL_DUMP"
  fi
}
trap cleanup EXIT

echo ">>> Verifying remote paths…"
ssh "$REMOTE_HOST" "LANG=C LC_ALL=C test -d '$REMOTE_PROJECT_ROOT' && test -d '$REMOTE_WEB_ROOT' && test -f '$REMOTE_SETTINGS'" \
  || { echo "ERROR: Remote settings.php not found: $REMOTE_SETTINGS"; exit 1; }

echo ">>> Verifying remote tools…"
ssh "$REMOTE_HOST" "LANG=C LC_ALL=C command -v php >/dev/null 2>&1" \
  || { echo "ERROR: php not found on remote"; exit 1; }

ssh "$REMOTE_HOST" "LANG=C LC_ALL=C command -v mysqldump >/dev/null 2>&1 || command -v mariadb-dump >/dev/null 2>&1" \
  || { echo "ERROR: mysqldump/mariadb-dump not found on remote"; exit 1; }

ssh "$REMOTE_HOST" "LANG=C LC_ALL=C command -v gzip >/dev/null 2>&1" \
  || { echo "ERROR: gzip not found on remote"; exit 1; }

echo ">>> Ensuring remote tmp dir exists…"
ssh "$REMOTE_HOST" "LANG=C LC_ALL=C mkdir -p '$REMOTE_TMP_DIR' && test -w '$REMOTE_TMP_DIR'" \
  || { echo "ERROR: Remote tmp not writable: $REMOTE_TMP_DIR"; exit 1; }

echo ">>> Remote web root:  $REMOTE_WEB_ROOT"
echo ">>> Remote site path: $REMOTE_SITE_PATH"
echo ">>> Remote settings:  $REMOTE_SETTINGS"
echo ">>> Remote db key:    $REMOTE_DB_KEY"
echo ">>> Remote dump:      $REMOTE_DUMP"
echo ">>> Local dump:       $LOCAL_DUMP"

echo ">>> Creating DB dump on remote (NO DRUSH)…"

ssh "$REMOTE_HOST" "LANG=C LC_ALL=C APP_ROOT='$REMOTE_WEB_ROOT' SITE_PATH='$REMOTE_SITE_PATH' SETTINGS_FILE='$REMOTE_SETTINGS' DB_KEY='$REMOTE_DB_KEY' REMOTE_DUMP='$REMOTE_DUMP' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

DUMP_BIN="$(command -v mysqldump || command -v mariadb-dump)"

# Pull creds from settings.php safely (define Drupal vars to avoid bootstrap errors)
CREDS="$(php <<'PHP'
<?php
error_reporting(E_ERROR);

// Define constants that settings.php may reference during include
$app_root = getenv('APP_ROOT');
define('DRUPAL_ROOT', $app_root);
define('APP_ROOT', $app_root);

if (!defined('SETTINGS_FILE')) {
  define('SETTINGS_FILE', getenv('SETTINGS_FILE'));
}

$databases  = [];
$site_path  = getenv('SITE_PATH');
$settings_file = getenv('SETTINGS_FILE');
$key        = getenv('DB_KEY');

include $settings_file;

if (!isset($databases[$key]['default'])) {
  fwrite(STDERR, "DB key not found in settings.php: {$key}\n");
  exit(2);
}
$db = $databases[$key]['default'];

$driver = $db['driver']      ?? 'mysql';
$name   = $db['database']    ?? '';
$user   = $db['username']    ?? '';
$pass   = $db['password']    ?? '';
$host   = $db['host']        ?? '';
$port   = $db['port']        ?? '';
$sock   = $db['unix_socket'] ?? '';

echo $driver, "\t", $name, "\t", $user, "\t", $pass, "\t", $host, "\t", $port, "\t", $sock;
PHP
)"

IFS=$'\t' read -r DB_DRIVER DB_NAME DB_USER DB_PASS DB_HOST DB_PORT DB_SOCK <<< "$CREDS"

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
  echo "ERROR: Could not read DB creds from settings.php" >&2
  exit 3
fi

ARGS=(--single-transaction --quick --routines --triggers --skip-comments -u "$DB_USER")

# Prefer unix socket if present
if [[ -n "$DB_SOCK" ]]; then
  ARGS+=( --socket="$DB_SOCK" )
else
  [[ -n "$DB_HOST" ]] && ARGS+=( -h "$DB_HOST" )
  [[ -n "$DB_PORT" ]] && ARGS+=( -P "$DB_PORT" )
fi

export MYSQL_PWD="$DB_PASS"

"$DUMP_BIN" "${ARGS[@]}" "$DB_NAME" | gzip -c > "$REMOTE_DUMP"
REMOTE_SCRIPT

echo ">>> Copying dump to local…"
umask 077
rsync -avz "${REMOTE_HOST}:${REMOTE_DUMP}" "${LOCAL_DUMP}"

echo ">>> Importing DB into DDEV…"
cd "$PROJECT_ROOT"
ddev import-db --file="$LOCAL_DUMP"

echo ">>> Clearing Drupal cache…"
ddev drush cr

echo ">>> Done. BOS is synced and ready."