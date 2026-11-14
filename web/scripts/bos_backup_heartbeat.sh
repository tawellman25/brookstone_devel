#!/bin/bash
#
# BOS nightly DB backup heartbeat — deliberately drush / PHP / Drupal-INDEPENDENT.
#
# bos_db_backup.sh emails when the dump or the S3 upload FAILS. It cannot email
# when it never RUNS: a lost crontab line, a moved script path, drush not
# starting under cron (the WEX failure mode, twice), a stalled cron daemon.
# Those nights look exactly like quiet ones. This watcher asks the other
# question — "does a fresh dump exist?" — from its own cron line, with nothing
# but bash + find + stat + mail, so it cannot die the way the thing it watches
# dies.
#
# Emails ONLY on a real problem (one mail, all findings):
#   - the backup folder is missing
#   - no dump newer than $MAX_AGE_MIN minutes (default 2160 = 36 h)
#   - the newest dump is EMPTY
#   - the newest dump is under $MIN_RATIO % of the previous one (a truncated
#     dump — nightly sizes move a few % at most; 158–189 MB in Nov 2026)
# A healthy night prints one OK line and sends nothing.
#
# Live cron (brookstoneadmin; the server clock is UTC and the backup runs
# at 02:30 UTC, so 04:30 UTC = 21:30 MT the evening before):
#   30 4 * * * LANG=C bash /home/brookstoneadmin/brookstone/web/scripts/bos_backup_heartbeat.sh >> $HOME/db_backup_heartbeat.log 2>&1
#
# Pairs with the OFF-box dead-man's switch (the BOS_HEARTBEAT_URL ping in
# bos_db_backup.sh): this one covers everything visible from the box, that one
# covers the box itself being gone. See Governance/deferred_work.md #21.
#
# Test without mail:
#   BOS_BACKUP_DIR=/some/dir BOS_HEARTBEAT_DRY=1 bash bos_backup_heartbeat.sh
# Exit status: 0 = healthy, 1 = alert raised (cron ignores it; tests use it).

set -u

DIR="${BOS_BACKUP_DIR:-$HOME/db_backups}"
MAX_AGE_MIN="${BOS_MAX_AGE_MIN:-2160}"
MIN_RATIO="${BOS_MIN_SIZE_RATIO:-50}"
ALERT_TO="${BOS_BACKUP_ALERT_TO:-todd@brookstoneoutdoors.com}"
DRY="${BOS_HEARTBEAT_DRY:-}"
HOST="$(hostname)"
NOW="$(date '+%F %T')"

problems=()

finish() {
  if [ "${#problems[@]}" -eq 0 ]; then
    echo "$NOW OK: $1"
    exit 0
  fi
  local body
  body="$(printf 'BOS DB backup heartbeat on %s found a problem:\n\n' "$HOST"
          printf '  - %s\n' "${problems[@]}"
          printf '\nFolder: %s\nChecked: %s\nThe backup script only emails when it runs and fails; this watcher fires when it did not run or left a bad file. Check: crontab -l, tail ~/db_backup.log, ls -la %s\n' "$DIR" "$NOW" "$DIR")"
  echo "$NOW ALERT: ${problems[*]}"
  if [ -n "$DRY" ]; then
    echo "--- (dry run: mail NOT sent) ---"; printf '%s\n' "$body"
  else
    printf '%s\n' "$body" | mail -s "BOS ALERT: no fresh DB backup on ${HOST}" "$ALERT_TO" 2>/dev/null
  fi
  exit 1
}

if [ ! -d "$DIR" ]; then
  problems+=("backup folder $DIR does not exist")
  finish ""
fi

# Newest two dumps by mtime.
mapfile -t dumps < <(ls -1t "$DIR"/bos-db-*.sql.gz 2>/dev/null)
if [ "${#dumps[@]}" -eq 0 ]; then
  problems+=("no bos-db-*.sql.gz dump at all in $DIR")
  finish ""
fi
newest="${dumps[0]}"
newest_size="$(stat -c %s "$newest")"
newest_age_min=$(( ( $(date +%s) - $(stat -c %Y "$newest") ) / 60 ))
summary="newest $(basename "$newest") is $(( newest_age_min / 60 ))h $(( newest_age_min % 60 ))m old, $(( newest_size / 1048576 )) MB; ${#dumps[@]} dump(s) kept"

# 1. Freshness — the stall case this watcher exists for.
fresh_count="$(find "$DIR" -maxdepth 1 -name 'bos-db-*.sql.gz' -mmin "-$MAX_AGE_MIN" 2>/dev/null | wc -l)"
if [ "$fresh_count" -eq 0 ]; then
  problems+=("no dump newer than $(( MAX_AGE_MIN / 60 )) h — $summary. The nightly cron did not run or did not finish.")
fi

# 2. Emptiness.
if [ "$newest_size" -eq 0 ]; then
  problems+=("newest dump $(basename "$newest") is EMPTY (0 bytes)")
fi

# 3. Size ratio vs the previous dump — catches a truncated dump.
if [ "${#dumps[@]}" -ge 2 ] && [ "$newest_size" -gt 0 ]; then
  prev="${dumps[1]}"
  prev_size="$(stat -c %s "$prev")"
  if [ "$prev_size" -gt 0 ] && [ $(( newest_size * 100 / prev_size )) -lt "$MIN_RATIO" ]; then
    problems+=("newest dump is $(( newest_size * 100 / prev_size ))% of the previous one ($(( newest_size / 1048576 )) MB vs $(( prev_size / 1048576 )) MB $(basename "$prev")) — below the $MIN_RATIO% floor; likely truncated")
  fi
  summary="$summary (previous $(( prev_size / 1048576 )) MB)"
fi

finish "$summary"
