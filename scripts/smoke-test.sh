#!/bin/sh

set -eu

readonly PROJECT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"

fail() {
    printf 'smoke-test: %s\n' "$*" >&2
    exit 1
}

usage() {
    printf 'Usage: %s skeleton|webapp\n' "$0" >&2
    exit 64
}

[ "$#" -eq 1 ] || usage
readonly VARIANT="$1"
case "$VARIANT" in
    skeleton|webapp) ;;
    *) usage ;;
esac

[ -n "${TE4G_TAILWIND_BINARY:-}" ] || fail 'TE4G_TAILWIND_BINARY must point to a pre-provisioned Tailwind CSS 4.3.3 binary.'
readonly TAILWIND_BINARY="$TE4G_TAILWIND_BINARY"

smoke_parent="$(CDPATH= cd -- "${TMPDIR:-/tmp}" && pwd -P)"
smoke_root="$(mktemp -d "${smoke_parent}/te4g-starter-smoke.XXXXXX")"

cleanup() {
    case "$smoke_root" in
        "$smoke_parent"/te4g-starter-smoke.*) ;;
        *)
            printf 'smoke-test: refusing to clean unvalidated path %s\n' "$smoke_root" >&2
            return
            ;;
    esac

    if [ -L "$smoke_root" ] || [ ! -d "$smoke_root" ]; then
        printf 'smoke-test: refusing to clean replaced path %s\n' "$smoke_root" >&2
        return
    fi

    rm -rf -- "$smoke_root"
}
trap cleanup EXIT HUP INT TERM

readonly APP_DIR="${smoke_root}/app"

printf 'Verifying the pinned Tailwind CSS executable...\n'
validated_tailwind="$(php -r '
require $argv[1]."/vendor/autoload.php";
echo Te4g\Starter\Tests\Fixtures\PinnedTailwindBinary::tailwind433()->validate($argv[2]);
' "$PROJECT_DIR" "$TAILWIND_BINARY")"
[ "$validated_tailwind" = "$TAILWIND_BINARY" ] || fail 'Tailwind validation returned an unexpected path.'
export TE4G_TAILWIND_BINARY="$validated_tailwind"

printf 'Creating a fresh Symfony 8.1 %s application...\n' "$VARIANT"
composer create-project 'symfony/skeleton:8.1.*' "$APP_DIR" --no-interaction --no-progress
if [ "$VARIANT" = 'webapp' ]; then
    (
        cd -- "$APP_DIR"
        composer require webapp --no-interaction --no-progress
    )
fi

export APP_ENV=dev
export DATABASE_URL='postgresql://user:password@127.0.0.1:1/unreachable?serverVersion=17'
export MAILER_DSN='null://null'

printf 'Installing te4g/starter 0.1.0 from a non-symlink path repository...\n'
(
    cd -- "$APP_DIR"
    composer config --json repositories.te4g-starter "{\"type\":\"path\",\"url\":\"${PROJECT_DIR}\",\"options\":{\"symlink\":false,\"versions\":{\"te4g/starter\":\"0.1.0\"}}}"
    composer require 'te4g/starter:0.1.0' --dev --no-interaction --no-progress --with-all-dependencies
)

mkdir -p "$APP_DIR/config/packages"
php -r '
$binary = json_encode($argv[2], JSON_THROW_ON_ERROR);
file_put_contents($argv[1]."/config/packages/zz_smoke_tailwind.yaml", "symfonycasts_tailwind:\n    binary: ".$binary."\n");
' "$APP_DIR" "$validated_tailwind"

printf 'Running the standalone generator with PostgreSQL unreachable...\n'
if install_output="$(cd -- "$APP_DIR" && vendor/bin/te4g-starter install --no-interaction 2>&1)"; then
    printf '%s\n' "$install_output"
else
    printf '%s\n' "$install_output" >&2
    fail "starter generation failed for ${VARIANT}."
fi

case "$install_output" in
    *SQLSTATE*|*'Connection refused'*|*'could not connect'*|*'database connection'*)
        fail 'starter generation attempted to connect to the database.'
        ;;
esac
database_file="$(find "$APP_DIR/var" -type f \( -name '*.db' -o -name '*.sqlite' \) -print -quit 2>/dev/null || true)"
[ -z "$database_file" ] || fail "starter generation created a database file: ${database_file}."

printf 'Removing the generator before any database use...\n'
(
    cd -- "$APP_DIR"
    composer remove te4g/starter --dev --no-interaction --no-progress
    composer config --unset repositories.te4g-starter
    if composer show te4g/starter >/dev/null 2>&1; then
        fail 'te4g/starter remains installed after Composer removal.'
    fi
    if rg -n 'Te4g\\|te4g/starter|te4g_user' src config templates assets tests composer.json; then
        fail 'generated application paths still reference the starter package.'
    fi
    [ -f STARTER_LICENSES.md ] || fail 'generated application lost STARTER_LICENSES.md after package removal.'
    rg -q 'END OF TERMS AND CONDITIONS' STARTER_LICENSES.md \
        || fail 'generated application license file does not contain the complete GPL terms.'
    rg -q 'Lucide Icons and Contributors' STARTER_LICENSES.md \
        || fail 'generated application license file does not contain the Lucide notice.'
)

printf 'Validating the independently owned generated application...\n'
(
    cd -- "$APP_DIR"
    php bin/console lint:container --no-interaction
    php bin/console lint:yaml config --no-interaction
    php bin/console lint:twig templates --no-interaction
    php bin/console tailwind:build --no-interaction
    php bin/console debug:asset-map --no-interaction
)

export DATABASE_URL='sqlite:///%kernel.project_dir%/var/smoke.db'

printf 'Creating the application-owned migration...\n'
(
    cd -- "$APP_DIR"
    php bin/console make:migration --no-interaction
)

migration_count=0
migration_file=''
for candidate in "$APP_DIR"/migrations/Version*.php; do
    [ -f "$candidate" ] || continue
    migration_count=$((migration_count + 1))
    migration_file="$candidate"
done
[ "$migration_count" -eq 1 ] || fail "expected exactly one migration, found ${migration_count}."
rg -q 'CREATE TABLE.*user' "$migration_file" || fail 'migration does not create the User schema.'
rg -q 'CREATE TABLE.*reset_password_request' "$migration_file" || fail 'migration does not create the reset-request schema.'

printf 'Applying and validating the application-owned schema...\n'
(
    cd -- "$APP_DIR"
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console doctrine:schema:validate
    APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
)

printf 'Running all generated functional tests...\n'
(
    cd -- "$APP_DIR"
    php bin/phpunit
)

printf 'Smoke test passed for %s.\n' "$VARIANT"
