#!/bin/sh

set -eu

readonly PROJECT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
readonly PREVIEW_PARENT="${PROJECT_DIR}/var"
readonly APP_DIR="${PROJECT_DIR}/var/preview"
readonly PREVIEW_PORT="${PREVIEW_PORT:-8001}"

fail() {
    printf 'preview: %s\n' "$*" >&2
    exit 1
}

for required_command in composer php symfony; do
    command -v "$required_command" >/dev/null 2>&1 \
        || fail "${required_command} is required."
done

php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' \
    || fail 'The pdo_sqlite PHP extension is required.'

case "$PREVIEW_PORT" in
    ''|*[!0-9]*) fail 'PREVIEW_PORT must be an integer between 1 and 65535.' ;;
esac
[ "$PREVIEW_PORT" -ge 1 ] && [ "$PREVIEW_PORT" -le 65535 ] \
    || fail 'PREVIEW_PORT must be an integer between 1 and 65535.'

if [ -L "$PREVIEW_PARENT" ]; then
    fail "refusing to use symlinked preview parent ${PREVIEW_PARENT}."
fi

mkdir -p -- "$PREVIEW_PARENT"
resolved_preview_parent="$(CDPATH= cd -- "$PREVIEW_PARENT" && pwd -P)"
[ "$resolved_preview_parent" = "$PREVIEW_PARENT" ] \
    || fail "preview parent resolved outside ${PREVIEW_PARENT}."

if [ -e "$APP_DIR" ] || [ -L "$APP_DIR" ]; then
    [ ! -L "$APP_DIR" ] \
        || fail "refusing to replace symlinked preview path ${APP_DIR}."
    [ -d "$APP_DIR" ] \
        || fail "refusing to replace non-directory preview path ${APP_DIR}."

    symfony server:stop --dir="$APP_DIR" >/dev/null 2>&1 || true
    rm -rf -- "$APP_DIR"
fi

printf 'Creating a fresh Symfony 8.1 web application in %s...\n' "$APP_DIR"
composer create-project 'symfony/skeleton:8.1.*' "$APP_DIR" --no-interaction --no-progress

(
    cd -- "$APP_DIR"
    composer require webapp --no-interaction --no-progress
    composer config --json repositories.te4g-starter "{\"type\":\"path\",\"url\":\"${PROJECT_DIR}\",\"options\":{\"symlink\":true}}"
    composer require 'te4g/starter:@dev' --dev --no-interaction --no-progress --with-all-dependencies
)

export APP_ENV=dev
export DATABASE_URL='sqlite:///%kernel.project_dir%/var/preview.db'
export MAILER_DSN='null://null'

{
    printf '%s\n' 'APP_ENV=dev'
    printf '%s\n' "DATABASE_URL='${DATABASE_URL}'"
    printf '%s\n' "MAILER_DSN='${MAILER_DSN}'"
} > "$APP_DIR/.env.local"

printf 'Generating the account application from this checkout...\n'
(
    cd -- "$APP_DIR"
    vendor/bin/te4g-starter install --no-interaction
    composer remove te4g/starter --dev --no-interaction --no-progress
    composer config --unset repositories.te4g-starter
    php bin/console make:migration --no-interaction
    php bin/console doctrine:migrations:migrate --no-interaction
)

printf '\nPreview ready. Useful pages:\n'
printf '  /\n'
printf '  /login\n'
printf '  /register\n'
printf '  /forgot-password\n'
printf '\nStarting HTTP server on http://127.0.0.1:%s\n' "$PREVIEW_PORT"
printf '\nThe server will stop when you press Ctrl+C.\n\n'

exec symfony server:start --dir="$APP_DIR" --port="$PREVIEW_PORT" --no-tls --open
