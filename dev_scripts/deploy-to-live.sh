#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="sewardsdevel"
REMOTE_ROOT="/home/sewardsdevel9/sewards10"
LOCAL_ROOT="/mnt/d/Development/websites/brookstone"
BACKUP_DIR="${REMOTE_ROOT}/backups"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")

MODE="${1-}"

#######################################
# ROLLBACK MODE
#######################################
if [[ "${MODE}" == "rollback" ]]; then
  echo ">>> ROLLBACK on ${REMOTE_HOST}…"

  ssh "${REMOTE_HOST}" "cd '${REMOTE_ROOT}' && \
    LATEST_BACKUP=\$(ls -1t '${BACKUP_DIR}'/backup-*.sql 2>/dev/null | head -n 1) && \
    if [ -z \"\$LATEST_BACKUP\" ]; then \
      echo 'No backup files found in ${BACKUP_DIR}'; \
      exit 1; \
    fi && \
    echo \"Using backup: \$LATEST_BACKUP\" && \
    vendor/bin/drush sset system.maintenance_mode 1 && \
    vendor/bin/drush cr && \
    vendor/bin/drush sql-drop -y && \
    vendor/bin/drush sql-cli < \"\$LATEST_BACKUP\" && \
    vendor/bin/drush cr && \
    vendor/bin/drush sset system.maintenance_mode 0 && \
    vendor/bin/drush cr"

  echo ">>> Rollback complete."
  exit 0
fi

#######################################
# DEPLOY MODE (default)
#######################################

echo ">>> Putting site in maintenance mode on ${REMOTE_HOST}…"
ssh "${REMOTE_HOST}" "cd '${REMOTE_ROOT}' && \
  vendor/bin/drush sset system.maintenance_mode 1 && \
  vendor/bin/drush cr"

echo ">>> Creating remote backup directory (if not exists)…"
ssh "${REMOTE_HOST}" "mkdir -p '${BACKUP_DIR}'"

echo ">>> Backing up LIVE database…"
ssh "${REMOTE_HOST}" "cd '${REMOTE_ROOT}' && \
  vendor/bin/drush sql-dump --result-file='${BACKUP_DIR}/backup-${TIMESTAMP}.sql'"

echo ">>> Syncing TO ${REMOTE_HOST} (codebase only)…"
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
  --exclude "backups/" \
  "${LOCAL_ROOT}/" \
  "${REMOTE_HOST}:${REMOTE_ROOT}/"

echo ">>> Code sync complete."

echo ">>> Running composer install + DB updates on ${REMOTE_HOST}…"
ssh "${REMOTE_HOST}" "bash -lc 'cd \"${REMOTE_ROOT}\" && composer install --no-dev -o && vendor/bin/drush updb -y && vendor/bin/drush cr'"

echo ">>> Turning off maintenance mode…"
ssh "${REMOTE_HOST}" "cd '${REMOTE_ROOT}' && \
  vendor/bin/drush sset system.maintenance_mode 0 && \
  vendor/bin/drush cr"

echo ">>> Deployment complete!"
echo ">>> Backup saved at: ${BACKUP_DIR}/backup-${TIMESTAMP}.sql"
