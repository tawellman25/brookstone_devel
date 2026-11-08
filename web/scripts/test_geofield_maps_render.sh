#!/usr/bin/env bash
#
# Do BOS's maps actually RENDER? — before/after harness for the geofield and
# geofield_map upgrades (Drupal 11 project).
#
# Why this exists as well as audit_geofield_usage.php: that script proves every
# plugin id referenced by config still RESOLVES. It cannot prove a map draws.
# geofield_map's maps are a views STYLE plugin, so a broken map is an empty div
# on a page that still returns HTTP 200 — the regression is invisible to any
# status-code smoke test. This fetches the real pages as a logged-in user and
# asserts the map markup and the Google Maps script are present.
#
# Usage (from the project root):
#   ./web/scripts/test_geofield_maps_render.sh
#   BASE=https://brookstone-d11.ddev.site ./web/scripts/test_geofield_maps_render.sh
#
# LOCAL/DEV ONLY. It logs in via `drush uli`, which mints a one-time login link
# for uid 1 — never point it at production.
#
# Exits non-zero if any checked page loses its map.

set -uo pipefail

BASE="${BASE:-https://brookstone-d11.ddev.site}"
DRUSH="${DRUSH:-ddev drush}"
JAR="$(mktemp -t bos-geofield-cj.XXXXXX)"
BODY="$(mktemp -t bos-geofield-body.XXXXXX)"
trap 'rm -f "$JAR" "$BODY"' EXIT

pass=0; fail=0

# --- log in -----------------------------------------------------------------
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

# --- check: path | human label | marker that must be present ----------------
# The marker is what proves the MAP is there, not merely the page.
CHECKS=(
  "/admin/office/work-orders/map|WO map (work_orders_map page_1)|geofield-google-map"
  "/teammates/properties/map|Crew property map (teammate_properties page_map)|geofield-google-map"
  "/admin/properties/mow-crew-route|Mow crew route (admin_mow_crew_route + attachment)|geofield-google-map"
  "/colorado/delta-county/delta/81416/680-willow-wood-ln|Property page header map (property_google_map_header)|geofield-google-map"
  "/properties/1/edit|Property EDIT form (geofield_map widget + geocode box)|geofield-map"
  "/properties/28490/edit|HOA EDIT form (hoa_boundary_map widget, field_boundary)|boundary-map"
  # The Route Editor emits NO maps.googleapis.com script server-side: the
  # controller passes the key through drupalSettings.bosRouteEditor.gmapKey and
  # route-editor.js loads the Maps API itself. So the honest assertion is that
  # the key arrived NON-EMPTY — which is precisely what fails if geofield_map
  # ever renames or relocates geofield_map.settings:gmap_api_key, the config
  # key this controller (and bos_hoa, and bos_service_request) reads directly.
  "/admin/office/work-orders/scheduling/route-editor|Route Editor (gmapKey from geofield_map.settings)|re:\"gmapKey\":\"[^\"]+\""
)

printf '%-58s %-6s %-9s %s\n' "PAGE" "HTTP" "GMAPS JS" "MAP MARKUP"
printf '%-58s %-6s %-9s %s\n' "----" "----" "--------" "----------"

for row in "${CHECKS[@]}"; do
  IFS='|' read -r path label marker <<< "$row"
  http="$(curl -sk -b "$JAR" -o "$BODY" -w '%{http_code}' --max-time 120 "${BASE}${path}")"
  gmaps="no";  grep -q 'maps.googleapis.com' "$BODY" && gmaps="yes"

  # A marker prefixed "re:" is an extended regex (used where presence alone is
  # not enough and the value must be non-empty).
  mark="MISSING"
  if [ "${marker#re:}" != "$marker" ]; then
    grep -qE -- "${marker#re:}" "$BODY" && mark="ok (regex)"
  else
    grep -q -- "$marker" "$BODY" && mark="ok ($marker)"
  fi

  ok=1
  [ "$http" = "200" ] || ok=0
  [ "$mark" = "MISSING" ] && ok=0

  if [ "$ok" = "1" ]; then pass=$((pass+1)); else fail=$((fail+1)); fi
  printf '%-58s %-6s %-9s %s\n' "${label:0:58}" "$http" "$gmaps" "$mark"
done

echo
echo "passed: $pass   failed: $fail"
if [ "$fail" -ne 0 ]; then
  echo "*** A map is gone. Compare against the pre-upgrade baseline before proceeding. ***"
  exit 1
fi
echo "All checked maps render."
