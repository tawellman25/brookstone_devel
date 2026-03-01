#!/usr/bin/env bash
set -euo pipefail
export LANG=C
export LC_ALL=C

# ====== Config ======
REMOTE_HOST="sewardsdevel"                  # SSH Host alias from ~/.ssh/config
REMOTE_ROOT="/home/sewardsdevel9/sewards10" # Composer project root (contains web/)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Optional safety: set to "--dry-run" to test safely.
DRY_RUN="" # "--dry-run"

# ====== Preflight ======
command -v ssh   >/dev/null 2>&1 || { echo "ERROR: ssh not found";   exit 1; }
command -v rsync >/dev/null 2>&1 || { echo "ERROR: rsync not found"; exit 1; }

if [[ ! -d "${LOCAL_ROOT}" ]]; then
  echo "ERROR: LOCAL_ROOT does not exist: ${LOCAL_ROOT}"
  exit 1
fi

SSH_CONFIG="$HOME/.ssh/config"
if [[ ! -f "${SSH_CONFIG}" ]]; then
  echo "ERROR: SSH config not found: ${SSH_CONFIG}"
  exit 1
fi

# Verify SSH alias works before rsync (fast fail).
if ! ssh -F "${SSH_CONFIG}" -o BatchMode=yes -o ConnectTimeout=8 "${REMOTE_HOST}" "echo ok" >/dev/null 2>&1; then
  echo "ERROR: SSH failed for host alias '${REMOTE_HOST}'. Check ${SSH_CONFIG} and key auth."
  exit 1
fi

echo "WARNING: This will sync FROM LIVE into LOCAL"
echo "    Source: ${REMOTE_HOST}:${REMOTE_ROOT}/"
echo "    Target: ${LOCAL_ROOT}/"
if [[ -n "${DRY_RUN}" ]]; then
  echo "    Mode:   DRY-RUN (${DRY_RUN})"
fi

printf "Type LIVE to continue: "
read -r CONFIRM
[[ "${CONFIRM}" == "LIVE" ]] || { echo "Aborted."; exit 1; }

echo ">>> Syncing FROM LIVE to local codebase…"

rsync -avz \
  ${DRY_RUN} \
  -e "ssh -F ${SSH_CONFIG}" \
  --no-perms --no-owner --no-group --no-times \
  --filter='protect dev_scripts/***' \
  --exclude "dev_scripts/" \
  --exclude ".DS_Store" \
  --exclude "Thumbs.db" \
  --exclude "__pycache__/" \
  --exclude "*.log" \
  --exclude "*.swp" \
  --exclude "*.bak" \
  --exclude "vendor/" \
  --exclude ".git/" \
  --exclude "node_modules/" \
  --exclude ".ddev/" \
  --exclude ".idea/" \
  --exclude ".vscode/" \
  --exclude ".env" \
  --exclude ".env.*" \
  --exclude "config-backup/" \
  --exclude "config/temp/" \
  --exclude "web/core/" \
  --exclude "web/modules/contrib/" \
  --exclude "web/themes/contrib/" \
  --exclude "web/profiles/" \
  --exclude "web/sites/*/files/" \
  --exclude "web/sites/*/private/" \
  --exclude "web/sites/default/settings.php" \
  --exclude "web/sites/default/settings.ddev.php" \
  --exclude "web/sites/default/services.yml" \
  --exclude "web/sites/default/default.settings.php" \
  --exclude "web/sites/default/default.services.yml" \
  --exclude "web/sites/default/.gitignore" \
  --exclude "web/s3_uri_verification.txt" \
  --exclude "aws/" \
  --exclude "aws-sdk-php/" \
  --exclude "aws_sdk/" \
  --exclude "web/libraries/aws/" \
  --exclude "web/libraries/aws-sdk-php/" \
  --exclude "web/libraries/aws_sdk/" \
  --exclude "web/modules/custom/*/aws/" \
  --exclude "web/modules/custom/*/aws-sdk-php/" \
  --exclude "web/modules/custom/*/aws_sdk/" \
  "${REMOTE_HOST}:${REMOTE_ROOT}/" \
  "${LOCAL_ROOT}/"

echo ">>> Done (code only; files are on S3)."
