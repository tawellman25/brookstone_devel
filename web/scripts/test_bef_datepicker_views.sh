#!/usr/bin/env bash
#
# Before/after harness for the better_exposed_filters 6.0.6 -> 7.2.0 upgrade
# (Drupal 11 project, step 3).
#
# Why these three views: `bef_datepicker` is configured on exactly three
# displays site-wide, all on a `created` filter, and two of them are BILLING
# screens:
#
#   admin_billing                    /admin/office/work-orders/billing
#   admin_weed_spray_billing         /admin/office/work-orders/billing/weed-spraying
#   admin_work_order_administration  /admin/office/work-orders/administration
#
# The upgrade removes drupal/jquery_ui_datepicker, _slider and _touch_punch,
# because BEF 6 was their sole composer requirer and BEF 7 requires none of
# them. The failure mode worth guarding against is an exposed date filter that
# silently stops rendering its input while the page still returns HTTP 200 —
# so this asserts the native date inputs are actually in the markup, not just
# that the page loaded.
#
# MEASURED 2026-11-06, before and after: all three pages 200, 3 `type="date"`
# inputs and 3 `bef-datepicker` classes each, unchanged. The jQuery UI asset
# count was 29 both times and all 29 are CORE's own
# /core/assets/vendor/jquery.ui/ (dialog, autocomplete, resizable) — core 10.6
# ships no datepicker widget at all, and BEF 6 only ever attached
# `better_exposed_filters/datepickers`, which declares no jQuery UI
# dependency. So the jQuery UI calendar was never actually active on these
# views; the fields were already plain native date inputs, and BEF 6's
# `$('.bef-datepicker').datepicker()` was calling a function that was never
# loaded. The upgrade removes that latent JS error rather than changing
# anything the office sees.
#
# Usage (from the project root):
#   ./web/scripts/test_bef_datepicker_views.sh
#   BASE=https://brookstone.ddev.site ./web/scripts/test_bef_datepicker_views.sh
#
# LOCAL/DEV ONLY — it logs in via `drush uli`, which mints a one-time login
# link for uid 1. Never point it at production.
#
# Exits non-zero if a page fails or loses its date input.

set -uo pipefail

BASE="${BASE:-https://brookstone-d11.ddev.site}"
DRUSH="${DRUSH:-ddev drush}"
JAR="$(mktemp -t bos-bef-cj.XXXXXX)"
BODY="$(mktemp -t bos-bef-body.XXXXXX)"
trap 'rm -f "$JAR" "$BODY"' EXIT

URL="$($DRUSH uli --no-browser 2>/dev/null | tr -d '\r' | grep -o 'http[^ ]*' | head -1)"
if [ -z "${URL:-}" ]; then
  echo "FATAL: could not obtain a login link from '$DRUSH uli'" >&2
  exit 2
fi
# The uli link is minted for the project's own hostname; rewrite to $BASE so an
# override actually takes effect instead of silently testing the wrong site.
URL="$(printf '%s' "$URL" | sed -E "s#^https?://[^/]+#${BASE}#")"
code="$(curl -sk -c "$JAR" -b "$JAR" -L -o /dev/null -w '%{http_code}' --max-time 60 "$URL")"
if [ "$code" != "200" ] || ! grep -q SESS "$JAR"; then
  echo "FATAL: login failed (HTTP $code, no session cookie)" >&2
  exit 2
fi
echo "Logged in OK against $BASE"
echo

PAGES=(
  "/admin/office/work-orders/billing|admin_billing (BILLING)"
  "/admin/office/work-orders/billing/weed-spraying|admin_weed_spray_billing (BILLING)"
  "/admin/office/work-orders/administration|admin_work_order_administration"
)

fail=0
printf '%-46s %-6s %-12s %-14s %s\n' "VIEW" "HTTP" "date inputs" "bef-datepicker" "stale refs"
printf '%-46s %-6s %-12s %-14s %s\n' "----" "----" "-----------" "--------------" "----------"

for row in "${PAGES[@]}"; do
  IFS='|' read -r path label <<< "$row"
  http="$(curl -sk -b "$JAR" -o "$BODY" -w '%{http_code}' --max-time 120 "${BASE}${path}")"
  di="$(grep -c 'type="date"' "$BODY")"
  bd="$(grep -c 'bef-datepicker' "$BODY")"
  # A reference to a module we deleted would mean a broken asset URL.
  stale="$(grep -c -e 'jquery_ui_datepicker' -e 'jquery_ui_slider' -e 'jquery_ui_touch_punch' "$BODY")"

  ok=1
  [ "$http" = "200" ] || ok=0
  [ "$di" -gt 0 ] || ok=0
  [ "$stale" -eq 0 ] || ok=0
  [ "$ok" = "1" ] || fail=$((fail+1))

  printf '%-46s %-6s %-12s %-14s %s\n' "${label:0:46}" "$http" "$di" "$bd" \
    "$([ "$stale" -eq 0 ] && echo none || echo "*** $stale ***")"
done

echo
echo "failed: $fail"
if [ "$fail" -ne 0 ]; then
  echo "*** A billing date filter lost its input, or references a deleted module. ***"
  exit 1
fi
echo "All three bef_datepicker views render their native date inputs."
