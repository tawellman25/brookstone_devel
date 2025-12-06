#!/usr/bin/env bash
set -euo pipefail

REMOTE_ROOT="/home/sewardsdevel9/sewards10"
LOCAL_ROOT="/mnt/d/Development/websites/brookstone"

echo ">>> Putting site in maintenance mode…"
ssh sewardsdevel "cd ${REMOTE_ROOT} && drush sset system.maintenance_mode 1 && drush cr"

echo ">>> Syncing TO sewardsdevel (codebase only)…"

rsync -avz \
  -e "ssh" \
  --delete \
  --no-perms --no-owner --no-group --no-times \
  --exclude "vendor/" \
  --exclude ".git/" \
  --exclude "node_modules/" \
  --exclude ".ddev/" \
  --exclude "web/core/" \
  --exclude "web/modules/contrib/" \
  --exclude "web/themes/contrib/" \
  --exclude "web/profiles/" \
  --exclude "web/sites/*/files/" \
  --exclude "web/sites/default/settings.ddev.php" \
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
  "${LOCAL_ROOT}/" \
  "sewardsdevel:${REMOTE_ROOT}/"

echo ">>> Taking site out of maintenance mode…"
ssh sewardsdevel "cd ${REMOTE_ROOT} && drush sset system.maintenance_mode 0 && drush cr"

echo ">>> Deploy complete (code only; DB untouched, files on S3)."
