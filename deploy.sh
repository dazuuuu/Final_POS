#!/usr/bin/env bash
# Deploys app/ and public/ to the dazuhub.com/pos subdomain on Hostinger.
set -euo pipefail

REMOTE_HOST="dazupos-hostinger"
REMOTE_PATH="domains/dazuhub.com/public_html/pos"
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Deploying app/ and public/ to $REMOTE_HOST:$REMOTE_PATH ..."

rsync -avz --delete \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'node_modules' \
  --exclude 'storage/' \
  --exclude 'config/secret.php' \
  --exclude 'config/mail.php' \
  --exclude 'config/database.php' \
  -e ssh \
  "$LOCAL_DIR/app/" "$REMOTE_HOST:$REMOTE_PATH/app/"

rsync -avz --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'uploads/' \
  -e ssh \
  "$LOCAL_DIR/public/" "$REMOTE_HOST:$REMOTE_PATH/public/"

echo "Done. Resetting OPcache..."
ssh "$REMOTE_HOST" "touch $REMOTE_PATH/public/index.php" || true

echo "Deploy complete."
