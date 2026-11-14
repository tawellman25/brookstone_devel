#!/usr/bin/env bash
# DEV ONLY — functional test of the Import material items form through the real HTTP form (the plain-file
# path is the no-JS path, so curl exercises exactly what a phone's Preview tap sends). Run from the project root:
#   ddev exec php web/scripts/make_test_receipt.php && web/scripts/import_form_test.sh   [LIST=<wo_material_list id>]
# Stops at the Preview step — nothing is imported; only temporary File entities are created (cron sweeps them).
# Case 2 needs a valid ANTHROPIC_API_KEY in .ddev/config.local.yaml (the one there was rejected on 2026-11-14).
set -u
URL=https://brookstone.ddev.site; LIST=${LIST:-6137}; SP=$(mktemp -d); JAR=$SP/jar.txt; rm -f $JAR
LINK=$(ddev drush user:login --name="Todd Wellman" --uri=$URL --no-browser 2>/dev/null | tail -1)
curl -sk -c $JAR -b $JAR -L "$LINK" -o /dev/null
form() { curl -sk -b $JAR "$URL/wo_material_list/$LIST/import" -o $SP/form.html; 
  FBI=$(grep -o 'name="form_build_id" value="[^"]*"' $SP/form.html | head -1 | sed 's/.*value="//; s/"$//');
  FTK=$(grep -o 'name="form_token" value="[^"]*"' $SP/form.html | head -1 | sed 's/.*value="//; s/"$//'); }
form
echo "== GET form: $(grep -c . $SP/form.html) lines; build_id ${FBI:0:12}… token ${FTK:0:8}…"
echo "   photo input first? $(grep -o -E 'name="files\[(photo|file)\]"' $SP/form.html | head -1)  | details: $(grep -o -E '<summary[^>]*>[^<]*' $SP/form.html | head -1 | sed 's/<summary[^>]*>//')"
echo "   enctype: $(grep -o 'enctype="[^"]*"' $SP/form.html | head -1) | accept photo: $(grep -o 'accept="image/\*"' $SP/form.html | head -1)"
post() { curl -sk -b $JAR -o "$1" -w '%{http_code} %{time_total}s' "${@:2}" -F "form_build_id=$FBI" -F "form_token=$FTK" -F "form_id=wo_material_list_import_items_form" -F "op=Preview" "$URL/wo_material_list/$LIST/import"; echo; }
echo "== 1. empty submit (expect a validation error, no preview) =="
post $SP/t1.html -F "supplier=" -F "paste="
grep -o -E 'Add a photo of the receipt[^<]{0,80}|No rows found[^<]{0,40}|rows — [0-9]+ exact' $SP/t1.html | head -2
form
echo "== 2. receipt PHOTO as the Preview submit (vision extraction) =="
post $SP/t2.html -F "supplier=Delta Hardware (12)" -F "learn=1" -F "paste=" -F "files[photo]=@tmp_test/receipt.jpg;type=image/jpeg"
grep -o -E '<strong>[0-9]+</strong> rows — [^<]*|Read from a <strong>photo</strong>[^<]*<strong>[^<]*|Could not read the invoice photo[^<]{0,120}|No line items could be read[^<]{0,60}|Add a photo of the receipt[^<]{0,30}' $SP/t2.html | head -4
echo "   identifiers on the preview: $(grep -o -E '<code>[^<]+</code>' $SP/t2.html | tr '\n' ' ' | head -c 200)"
echo "   descriptions: $(grep -o -E 'wo-import-desc">[^<]+' $SP/t2.html | sed 's/.*">//' | tr '\n' '|' | head -c 200)"
echo "   qty/cost inputs: $(grep -o -E 'name="rows\[[0-9]+\]\[(quantity|unit_cost)\]"[^>]*value="[^"]*"' $SP/t2.html | sed 's/.*\[\(quantity\|unit_cost\)\].*value="\([^"]*\)"/\1=\2/' | tr '\n' ' ')"
form
echo "== 3. CSV file as the Preview submit =="
post $SP/t3.html -F "supplier=Delta Hardware (12)" -F "learn=1" -F "paste=" -F "files[file]=@tmp_test/rows.csv;type=text/csv"
grep -o -E '<strong>[0-9]+</strong> rows — [^<]*' $SP/t3.html | head -1; echo "   identifiers: $(grep -o -E '<code>[^<]+</code>' $SP/t3.html | tr '\n' ' ')"
form
echo "== 4. wrong extension for the photo (expect an extension error) =="
post $SP/t4.html -F "supplier=" -F "paste=" -F "files[photo]=@tmp_test/rows.csv;type=text/csv"
grep -o -E 'Only files with the following extensions[^<]{0,60}|extension[^<]{0,80}' $SP/t4.html | head -1
form
echo "== 5. pasted rows (the third path) =="
post $SP/t5.html -F "supplier=" -F "paste=3539541, 2, 6.59"
grep -o -E '<strong>[0-9]+</strong> rows — [^<]*' $SP/t5.html | head -1
