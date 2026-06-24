#!/bin/bash
#
# WEX daily fetch failure watcher — deliberately drush / PHP / Drupal-INDEPENDENT.
#
# The failure mode this guards against is drush itself not starting under cron
# (CGI-PHP via the CloudLinux wrapper, or the global drush PHAR re-exec'ing
# through it). That failure is SILENT — nothing alerts, only ~/wex_fetch.log
# shows it, and it has caused two multi-day outages. So this watcher must NOT
# depend on drush/Drupal: it only inspects the log the fetch cron leaves behind.
#
# Runs ~15 min after the 07:00 fetch. Emails ONLY on a real failure:
#   - no log / empty log (the import isn't running at all), or
#   - the latest log block isn't from today (cron didn't fire), or
#   - the latest block lacks the "fetch-email complete" success line
#     (drush crashed, IMAP auth failed, URL extract failed, etc.).
# A clean run that imported nothing ("0 UNSEEN") still prints "complete", so
# quiet weekend / no-fuel days do NOT alert.
#
# Live: run from the deployed repo copy via cron (kept in sync by deploys):
#   15 7 * * * bash /home/brookstoneadmin/brookstone/web/scripts/wex_alert_check.sh >> $HOME/wex_alert.log 2>&1
# Pairs with the 07:00 wex:fetch-email cron. See
# __BOS_AI/Modules/wex_fuel_import_workflow.md and
# __BOS_AI/Governance/drupal_bos_gotchas.md ("cron drush invocation fails silently").

LOG="${WEX_FETCH_LOG:-$HOME/wex_fetch.log}"
ALERT_TO="${WEX_ALERT_TO:-todd@brookstoneoutdoors.com}"
HOST="$(hostname)"
TODAY="$(date '+%a %b %e')"   # e.g. "Wed Jun 24" — matches the cron's $(date) header

send_alert() {
  printf '%s\n' "$1" | mail -s "BOS ALERT: WEX fuel import failed on ${HOST}" "$ALERT_TO"
}

if [ ! -s "$LOG" ]; then
  send_alert "WEX watcher on ${HOST}: no fetch log at ${LOG} (or it is empty). The daily fuel import may not be running at all. Check the crontab and ~/wex_fetch.log."
  exit 0
fi

# Latest run block = from the final "=== WEX fetch" header to end of file.
BLOCK="$(awk '/^=== WEX fetch /{blk=""} {blk = blk $0 ORS} END{printf "%s", blk}' "$LOG")"
HEADER="$(printf '%s' "$BLOCK" | head -1)"

case "$HEADER" in
  *"$TODAY"*) : ;;  # ran today — good
  *)
    send_alert "$(printf 'WEX watcher on %s: the most recent fetch in %s is NOT from today (%s).\nCron may not have fired. Latest block header:\n  %s' "$HOST" "$LOG" "$TODAY" "$HEADER")"
    exit 0
    ;;
esac

if printf '%s' "$BLOCK" | grep -q 'fetch-email complete'; then
  exit 0   # healthy, including clean "0 UNSEEN" days
fi

send_alert "$(printf 'WEX daily fuel import did NOT complete cleanly on %s (cron fired but the fetch failed).\nCheck %s — likely the drush invocation regressed again.\n\n--- latest log block ---\n%s' "$HOST" "$LOG" "$(printf '%s' "$BLOCK" | head -40)")"
exit 0
