#!/usr/bin/env bash
#
# Do the address and inline_entity_form WIDGETS still render? — before/after
# harness for drupal/address 1.12 -> 2.x and inline_entity_form 1.0 -> 3.x
# (Drupal 11 project, step 5).
#
# Why: both are majors that BOS reaches only through Field UI config — no
# custom code calls either API — so the failure mode is a form that returns
# HTTP 200 with the widget silently missing, or a widget plugin id that no
# longer resolves and leaves the field off the form. This fetches the real
# forms as uid 1 and asserts the widget's OWN form inputs are present.
#
# The surfaces, from active config (2026-11-06):
#   address_default             manufacturer (2 fields), taxonomy dump_location
#   address_country_default     address.profile_mailing_addresses (field_country)
#   inline_entity_form_complex  config_pages business_setting (the RATE TABLES —
#                               4 sq_ft_break_points refs), teammate_profile,
#                               emergency_contacts, property_sprinkler_info
#   inline_entity_form_simple   74 instances — 69 of them the residential
#                               CONTRACT form's section slots, + contacts, supplier
#
# Markers were taken from the rendered pages, not guessed. The first version of
# this harness failed 5 of 7 on the BASELINE for three reasons worth keeping:
# `[0]` in a marker is a bracket expression to basic grep (so a present input
# "missed"); `\"` inside a PHP single-quoted string stays a literal backslash;
# and an IEF complex field with NO referenced entities renders only its "Add"
# button, so `ief-entity-table` is the wrong marker for an empty field. All
# matching is now fixed-string unless a marker is prefixed `re:` (regex count)
# or `uniq:` (regex, count DISTINCT matches — used for "how many section slots
# rendered a sub-form").
#
# Entity ids are resolved at run time via drush, so this works on any copy.
# The counts are printed so a before/after diff shows partial loss, not just
# total loss.
#
#   ./web/scripts/test_address_ief_forms.sh
#   BASE=https://brookstone.ddev.site ./web/scripts/test_address_ief_forms.sh
#
# LOCAL/DEV ONLY — mints a `drush uli` link for uid 1. Never point at live.

set -uo pipefail
BASE="${BASE:-https://brookstone-d11.ddev.site}"
DRUSH="${DRUSH:-ddev drush}"
JAR="$(mktemp -t bos-aief-cj.XXXXXX)"; BODY="$(mktemp -t bos-aief-body.XXXXXX)"
trap 'rm -f "$JAR" "$BODY"' EXIT

URL="$($DRUSH uli --no-browser 2>/dev/null | tr -d '\r' | grep -o 'http[^ ]*' | head -1)"
[ -n "${URL:-}" ] || { echo "FATAL: no login link" >&2; exit 2; }
URL="$(printf '%s' "$URL" | sed -E "s#^https?://[^/]+#${BASE}#")"
code="$(curl -sk -c "$JAR" -b "$JAR" -L -o /dev/null -w '%{http_code}' --max-time 60 "$URL")"
{ [ "$code" = "200" ] && grep -q SESS "$JAR"; } || { echo "FATAL: login failed (HTTP $code)" >&2; exit 2; }
echo "Logged in OK against $BASE"; echo

# path|label|marker|min   (markers use NO double quotes and NO "|" — the pipe is
# the row delimiter, so a regex alternation like (a|b) silently splits the row)
mapfile -t CHECKS < <($DRUSH php:eval '
$etm = \Drupal::entityTypeManager();
$newest = function ($type, $bundle, $idkey = "id") use ($etm) {
  $q = $etm->getStorage($type)->getQuery()->accessCheck(FALSE)->sort($idkey, "DESC")->range(0, 1);
  $q->condition($type === "taxonomy_term" ? "vid" : "type", $bundle);
  $ids = $q->execute(); return $ids ? reset($ids) : NULL;
};
$r = [];
if ($id = $newest("manufacturer", "manufacturer"))            $r[] = "/manufacturer/$id/edit|manufacturer edit (address_default x2)|uniq:name=.field_[a-z]+_address\[0\]\[address\]\[country_code\]|2";
if ($id = $newest("taxonomy_term", "dump_location", "tid"))     $r[] = "/taxonomy/term/$id/edit|dump_location term edit (address_default)|field_address[0][address][country_code]|1";
if ($id = $newest("address", "profile_mailing_addresses"))    $r[] = "/address/$id/edit|mailing address edit (address_country_default)|field_country[0][value]|1";
$r[] = "/admin/structure/config_pages/business_setting|business_setting RATE TABLES (IEF complex x4)|ief-entity-table|4";
if ($id = $newest("profile", "teammate_profile", "profile_id")) $r[] = "/profile/$id/edit|teammate_profile edit (IEF complex x2, empty -> Add buttons)|uniq:name=.ief-field_[a-z_]+-form-add|2";
if ($id = $newest("contacts", "emergency_contacts"))          $r[] = "/contacts/$id/edit|emergency_contacts edit (IEF complex x2)|uniq:name=.ief-field_[a-z_]+-form-add.|1";
if ($id = $newest("contracts", "residential"))                $r[] = "/contracts/$id/edit|RESIDENTIAL CONTRACT edit (IEF simple x69 slots)|uniq:name=.[a-z_]+\[0\]\[inline_entity_form\]|1";
if ($id = $newest("contacts", "contact"))                     $r[] = "/contacts/$id/edit|contact edit (IEF simple x2)|[0][inline_entity_form][|1";
if ($id = $newest("supplier", "supplier"))                    $r[] = "/supplier/$id/edit|supplier edit (IEF simple x3)|[0][inline_entity_form][|1";
print implode("\n", $r) . "\n";
' 2>/dev/null | grep '|')

pass=0; fail=0
printf '%-62s %-5s %-8s %s\n' "FORM" "HTTP" "FOUND" "VERDICT"; printf '%-62s %-5s %-8s %s\n' "----" "----" "-----" "-------"
for row in "${CHECKS[@]}"; do
  IFS='|' read -r path label marker min <<< "$row"
  http="$(curl -sk -b "$JAR" -o "$BODY" -w '%{http_code}' --max-time 120 "${BASE}${path}")"
  case "$marker" in
    uniq:*) n="$(grep -oE -- "${marker#uniq:}" "$BODY" | sort -u | wc -l)" ;;
    re:*)   n="$(grep -oE -- "${marker#re:}"   "$BODY" | wc -l)" ;;
    *)      n="$(grep -oF -- "$marker"          "$BODY" | wc -l)" ;;
  esac
  ok=1; [ "$http" = "200" ] || ok=0; [ "$n" -ge "$min" ] || ok=0
  if [ "$ok" = 1 ]; then pass=$((pass+1)); verdict="ok"; else fail=$((fail+1)); verdict="*** FAIL (need >= $min) ***"; fi
  printf '%-62s %-5s %-8s %s\n' "${label:0:62}" "$http" "$n" "$verdict"
done
echo; echo "passed: $pass   failed: $fail"
[ "$fail" -eq 0 ] || { echo "*** A widget is gone from a form that still returns 200. ***"; exit 1; }
echo "All address / IEF widgets render."
