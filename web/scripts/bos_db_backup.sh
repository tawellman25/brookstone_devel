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
# Off-server copies are still recommended (pull via
# dev_scripts/brookstone-sync-db-from-live.sh, or push to S3) — these dumps live
# on the same disk as the DB, so they only protect against logical loss, not a
# disk/server failure.

set -u

KEEP=14
DIR="$HOME/db_backups"
PROJECT="/home/brookstoneadmin/brookstone"
DRUSH="/opt/alt/php83/usr/bin/php $PROJECT/vendor/drush/drush/drush.php"
ALERT_TO="${BOS_BACKUP_ALERT_TO:-todd@brookstoneoutdoors.com}"
HOST="$(hostname)"

fail() {
  echo "$(date '+%F %T') BACKUP FAILED: $1"
  printf 'BOS nightly DB backup FAILED on %s: %s\n' "$HOST" "$1" \
    | mail -s "BOS ALERT: nightly DB backup failed on ${HOST}" "$ALERT_TO" 2>/dev/null
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

# Rotate: keep the $KEEP newest, prune the rest.
ls -1t "$DIR"/bos-db-*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r f; do
  echo "prune: $f"
  rm -f "$f"
done
echo "retained: $(ls -1 "$DIR"/bos-db-*.sql.gz 2>/dev/null | wc -l) dump(s) (keep=$KEEP)"
