#!/usr/bin/env bash
#
# bos-ai-sync.sh — regenerate the flat _upload_bundle/ staging dir for Claude.ai upload.
#
# The bundle is a generated artifact (gitignored). Regenerate it after any change to
# __BOS_AI/ .md/.docx content so the uploaded project-knowledge copy stays current.
# Claude Code runs this automatically at the end of a session where __BOS_AI/ content
# changed (see CLAUDE.md → "Regenerating the bundle"); you can also run it by hand:
#
#     ./__BOS_AI/bos-ai-sync.sh
#
# Staging rules (kept in lockstep with CLAUDE.md → "__BOS_AI Documentation Bundle"):
#   - flat copy of every .md/.docx under __BOS_AI/
#   - excludes Archive/, _upload_bundle/, .last_bundle/, hidden files, this script, the zip
#   - collision renames: Modules/estimate.md → estimate_module.md,
#                        Modules/weed_spray_reconciliation.md → weed_spray_reconciliation_module.md
#   - copy-only (shutil.copy2, preserves mtimes) — source files are never modified
#
# Exits non-zero if a duplicate basename slips through or an expected file is missing,
# so an automated caller can detect a broken bundle.

set -euo pipefail

# Resolve repo root as the parent of this script's dir, so it works from any CWD.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

rm -rf __BOS_AI/_upload_bundle
mkdir -p __BOS_AI/_upload_bundle

python3 - <<'PY'
import os, shutil, sys
SRC = '__BOS_AI'; DEST = '__BOS_AI/_upload_bundle'
EXCLUDE_DIRS = {'Archive', '_upload_bundle', '.last_bundle'}
EXCLUDE_FILES = {'bos-ai-sync.sh', '__BOS_AI.zip'}
ALLOWED_EXT = {'.md', '.docx'}
RENAME = {
    'Modules/estimate.md': 'estimate_module.md',
    'Modules/weed_spray_reconciliation.md': 'weed_spray_reconciliation_module.md',
}
n = 0
for root, dirs, files in os.walk(SRC):
    rel_root = os.path.relpath(root, SRC)
    parts = [] if rel_root == '.' else rel_root.split(os.sep)
    if any(p.startswith('.') or p in EXCLUDE_DIRS for p in parts):
        dirs[:] = []
        continue
    dirs[:] = [d for d in dirs if not d.startswith('.') and d not in EXCLUDE_DIRS]
    for f in files:
        if f.startswith('.') or f in EXCLUDE_FILES:
            continue
        if os.path.splitext(f)[1].lower() not in ALLOWED_EXT:
            continue
        full = os.path.join(root, f)
        rel = os.path.relpath(full, SRC).replace(os.sep, '/')
        shutil.copy2(full, os.path.join(DEST, RENAME.get(rel, f)))
        n += 1
print(f"staged {n} files")
PY

# --- verification (mirrors CLAUDE.md → "Verification") ---
fail=0
dupes="$(ls __BOS_AI/_upload_bundle | sort | uniq -d)"
if [ -n "${dupes}" ]; then
  echo "ERROR: duplicate basenames in bundle:" >&2
  echo "${dupes}" >&2
  fail=1
fi
for required in ROADMAP.md estimate.md estimate_module.md \
                weed_spray_reconciliation.md weed_spray_reconciliation_module.md; do
  if [ ! -f "__BOS_AI/_upload_bundle/${required}" ]; then
    echo "ERROR: expected bundle file missing: ${required}" >&2
    fail=1
  fi
done

echo "bundle file count: $(ls __BOS_AI/_upload_bundle | wc -l)"
if [ "${fail}" -ne 0 ]; then
  echo "bundle regeneration FAILED verification" >&2
  exit 1
fi
echo "bundle OK: __BOS_AI/_upload_bundle/ ready for upload"
