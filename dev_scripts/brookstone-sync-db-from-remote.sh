#!/usr/bin/env bash
set -euo pipefail

# Figure out project root based on where this script lives
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

REMOTE_SSH="sewardsdevel"
REMOTE_DOCROOT="~/sewards10"
DUMP_NAME="brookstone-sync.sql.gz"
REMOTE_DUMP="~/tmp/${DUMP_NAME}"
LOCAL_DUMP="${PROJECT_ROOT}/${DUMP_NAME}"

echo ">>> Creating DB dump on remote…"
ssh "$REMOTE_SSH" "cd $REMOTE_DOCROOT && drush sql:dump --result-file=${REMOTE_DUMP} --gzip -y"

echo ">>> Copying dump to local…"
scp "${REMOTE_SSH}:${REMOTE_DUMP}" "${LOCAL_DUMP}"

echo ">>> Cleaning remote dump…"
ssh "$REMOTE_SSH" "rm -f ${REMOTE_DUMP}"

echo ">>> Restarting DDEV…"
cd "$PROJECT_ROOT"
ddev restart

echo ">>> Importing DB into DDEV…"
ddev import-db --file="${DUMP_NAME}"

echo ">>> Done."
