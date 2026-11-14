#!/bin/bash
#
# Nightly BOS live database backup, with rotation.
#
# Self-managed because the host's account backups are unreliable (they stop when
# the account file/inode count gets high). Keeps the newest $KEEP gzipped dumps
# in ~/db_backups and prunes older ones, so file count + disk stay bounded.
#
# Cron (brookstoneadmin crontab):
#   30 2 * * * LANG=C bash /home/brookstoneadmin/brookstone/web/scripts/bos_db_backup.sh >> $HOME/db_backup.log 2>&1
#
# NOTE the drush invocation: the Alt-PHP CLI binary on the project's own
# vendor/drush/drush.php — the same form the WEX cron uses. Do NOT use
# /usr/local/bin/drush (the global PHAR re-execs through the CGI wrapper and
# dies silently under cron). See drupal_bos_gotchas.md.
#
# Off-site copy: after each local dump this pushes the gzip to S3 (a separate
# failure domain from the Hosting.com data center — see the 2026-08 Phoenix DC
# thermal outage). Credentials + bucket come from an off-git env file
# ($HOME/.bos_s3_backup.env, chmod 600) so no secret lives in the repo; the
# upload itself uses web/scripts/s3_backup_upload.php against the AWS SDK already
# in vendor/ (no AWS CLI needed). S3 failure alerts by email but does NOT abort
# the local backup (local dump stays primary).
#
# Dead-man's switch (2026-11-14): if BOS_HEARTBEAT_URL is set in the same env
# file (a healthchecks.io ping URL, https://hc-ping.com/<uuid>), the script
# pings it ONLY after the dump is on S3 — so the ping means "an off-site copy
# of tonight's dump exists" — and pings <url>/fail on any failure. The service
# emails when a night's ping never arrives, which covers everything this
# script cannot report on itself: cron dead, box dead, mail dead. The on-box
# half is web/scripts/bos_backup_heartbeat.sh. Pings never abort the backup.

set -u

KEEP=14
DIR="$HOME/db_backups"
PROJECT="/home/brookstoneadmin/brookstone"
DRUSH="/opt/alt/php83/usr/bin/php $PROJECT/vendor/drush/drush/drush.php"
PHP_BIN="/opt/alt/php83/usr/bin/php"
S3_ENV="$HOME/.bos_s3_backup.env"
ALERT_TO="${BOS_BACKUP_ALERT_TO:-todd@brookstoneoutdoors.com}"
HOST="$(hostname)"

# S3 credentials + bucket + the heartbeat URL live in one off-git env file.
if [ -f "$S3_ENV" ]; then
  set -a; . "$S3_ENV"; set +a
fi
HEARTBEAT_URL="${BOS_HEARTBEAT_URL:-}"

# hc_ping [fail] "<body>" — dead-man's-switch ping; no-op without a URL, never
# aborts (an unreachable hc-ping.com just means the switch fires on its own).
hc_ping() {
  [ -n "$HEARTBEAT_URL" ] || return 0
  local url="$HEARTBEAT_URL"
  [ "${1:-}" = "fail" ] && url="$url/fail"
  if curl -fsS -m 10 --retry 5 -o /dev/null --data-raw "${2:-}" "$url"; then
    echo "heartbeat: pinged ${1:-ok}"
  else
    echo "$(date '+%F %T') heartbeat ping (${1:-ok}) FAILED — hc-ping unreachable; the switch fires on its own"
  fi
  return 0
}

fail() {
  echo "$(date '+%F %T') BACKUP FAILED: $1"
  printf 'BOS nightly DB backup FAILED on %s: %s\n' "$HOST" "$1" \
    | mail -s "BOS ALERT: nightly DB backup failed on ${HOST}" "$ALERT_TO" 2>/dev/null
  hc_ping fail "BACKUP FAILED on $HOST: $1"
  exit 1
}

mkdir -p "$DIR" || fail "cannot create $DIR"
cd "$PROJECT" || fail "cannot cd to $PROJECT"

STAMP="$(date +%Y%m%d-%H%M%S)"
BASE="$DIR/bos-db-$STAMP.sql"   # drush --gzip writes ${BASE}.gz

echo "=== BOS DB backup $(date) ==="
$DRUSH sql:dump --gzip --result-file="$BASE" || fail "drush sql:dump returned non-zero"
[ -s "${BASE}.gz" ] || fail "dump missing or empty: ${BASE}.gz"
echo "OK: ${BASE}.gz ($(du -h "${BASE}.gz" | cut -f1))"

# Off-site push to S3 (local backup stays primary; S3 failure alerts, no abort).
if [ -f "$S3_ENV" ]; then
  if "$PHP_BIN" "$PROJECT/web/scripts/s3_backup_upload.php" "${BASE}.gz" 2>>"$DIR/s3_upload.err"; then
    echo "S3: uploaded $(basename "${BASE}.gz") to ${BOS_S3_BACKUP_BUCKET:-?}"
    # Off-site copy exists → tonight's heartbeat. Body shows in the check's log.
    hc_ping ok "OK $(basename "${BASE}.gz") $(du -h "${BASE}.gz" | cut -f1) on S3 ${BOS_S3_BACKUP_BUCKET:-?} from $HOST"
  else
    echo "$(date '+%F %T') S3 UPLOAD FAILED (local backup OK)"
    printf 'BOS S3 off-site upload FAILED on %s (local dump OK: %s). See %s\n' \
      "$HOST" "${BASE}.gz" "$DIR/s3_upload.err" \
      | mail -s "BOS ALERT: S3 backup upload failed on ${HOST}" "$ALERT_TO" 2>/dev/null
    hc_ping fail "S3 UPLOAD FAILED on $HOST (local dump OK: $(basename "${BASE}.gz"))"
  fi
else
  echo "S3: skipped (no $S3_ENV) — no heartbeat ping either (the ping means an off-site copy exists)"
fi

# Rotate: keep the $KEEP newest, prune the rest.
ls -1t "$DIR"/bos-db-*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r f; do
  echo "prune: $f"
  rm -f "$f"
done
echo "retained: $(ls -1 "$DIR"/bos-db-*.sql.gz 2>/dev/null | wc -l) dump(s) (keep=$KEEP)"
