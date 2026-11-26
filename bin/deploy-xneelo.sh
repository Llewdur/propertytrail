#!/usr/bin/env bash
# Per-release deploy for the Xneelo box (zekini-com/standards, docs/xneelo-hosting.md
# "Where deploy.sh lives"). Run by the box-side trigger /var/www/propertytrail/deploy.sh:
#
#   bash bin/deploy-xneelo.sh APP_DIR RELEASE_DIR SHA
#
# It builds in the RELEASE_DIR it is handed and never clones or deletes a release. The trigger
# holds .deploy.lock. There is no Node build: the web bundle in public/spa is committed.
set -euo pipefail
unset CDPATH

APP_NAME=propertytrail
APP_DIR="${1:-${APP_DIR:-}}"
RELEASE_DIR="${2:-${RELEASE_DIR:-}}"
SHA="${3:-${SHA:-}}"
NOTIFY_LIB="${DEPLOY_NOTIFY_LIB:-/var/www/deploy-notify.sh}"

if [ -f "$NOTIFY_LIB" ]; then
  # shellcheck source=/dev/null
  . "$NOTIFY_LIB"
fi

step="argument check"
on_failure() {
  echo "deploy-xneelo [$APP_NAME]: FAILED at: $step" >&2
  if declare -F deploy_notify_failure >/dev/null; then
    deploy_notify_failure "$APP_NAME" "production" "${SHA:-unknown}: $step"
  fi
}
trap on_failure ERR

if [ -z "$APP_DIR" ] || [ -z "$RELEASE_DIR" ] || [ -z "$SHA" ]; then
  echo "usage: bin/deploy-xneelo.sh APP_DIR RELEASE_DIR SHA" >&2
  exit 2
fi
if ! [[ "$SHA" =~ ^[0-9a-f]{7,40}$ ]]; then
  echo "deploy-xneelo: SHA '$SHA' is not a git SHA, refusing" >&2
  exit 2
fi
if [ ! -d "$RELEASE_DIR" ] || [ "$(readlink -f "$RELEASE_DIR")" != "$(readlink -f "$APP_DIR/releases/$SHA")" ]; then
  echo "deploy-xneelo: RELEASE_DIR '$RELEASE_DIR' is not $APP_DIR/releases/$SHA, refusing" >&2
  exit 2
fi

step="shared state check"
if [ ! -f "$APP_DIR/shared/.env" ]; then
  echo "ERROR: $APP_DIR/shared/.env is missing" >&2
  false
fi

step="php binary"
# Build with the PHP minor that serves the app: the newest per-app FPM pool, else PHP_MINOR.
FPM_MINOR=""
for FPM_CONF in /etc/php/*/fpm/apps/"$APP_NAME".conf; do
  if [ -f "$FPM_CONF" ]; then
    FPM_MINOR="${FPM_CONF#/etc/php/}"
    FPM_MINOR="${FPM_MINOR%%/*}"
  fi
done
PHP_BIN="php${FPM_MINOR:-${PHP_MINOR:-8.5}}"
command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "ERROR: $PHP_BIN is not installed" >&2; false; }
COMPOSER_BIN="$(command -v composer)"

cd "$RELEASE_DIR"

step="link shared storage and .env"
mkdir -p "$APP_DIR/shared/storage/app/private" "$APP_DIR/shared/storage/app/public" \
  "$APP_DIR/shared/storage/framework/cache/data" "$APP_DIR/shared/storage/framework/sessions" \
  "$APP_DIR/shared/storage/framework/views" "$APP_DIR/shared/storage/logs"
rm -rf storage
ln -s ../../shared/storage storage
ln -sfn ../../shared/.env .env

step="composer install"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

step="migrate"
"$PHP_BIN" artisan migrate --force

step="cache config, routes and views"
"$PHP_BIN" artisan config:cache
# The cached config holds every secret in shared/.env: only the deploy user and PHP-FPM read it.
sudo -n chown ubuntu:www-data bootstrap/cache/config.php
sudo -n chmod 640 bootstrap/cache/config.php
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

step="ownership and permissions"
sudo -n chown -R ubuntu:www-data "$RELEASE_DIR" "$APP_DIR/shared/storage"
sudo -n chmod -R ug+rwX "$APP_DIR/shared/storage"

step="flip current"
ln -sfn "$RELEASE_DIR" "$APP_DIR/.current-$SHA"
mv -T "$APP_DIR/.current-$SHA" "$APP_DIR/current"

step="reload php-fpm"
if [ -n "$FPM_MINOR" ]; then
  sudo -n systemctl reload "php${FPM_MINOR}-fpm@${APP_NAME}"
else
  sudo -n systemctl reload "php${PHP_MINOR:-8.5}-fpm"
fi

step="queue:restart"
"$PHP_BIN" artisan queue:restart

if declare -F deploy_notify_success >/dev/null; then
  deploy_notify_success "$APP_NAME" "production" "$SHA"
fi

step="prune releases"
if command -v prune-releases >/dev/null 2>&1; then
  prune-releases --apply --caller-holds-lock "$APP_DIR" || echo "WARNING: prune-releases failed; the nightly run retries" >&2
fi

echo "deploy-xneelo [$APP_NAME]: deployed $SHA."
