#!/usr/bin/env bash

set -Eeuo pipefail

readonly PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
readonly TAILWIND_SHA256_DARWIN_ARM64='cdf646702987a743464dff4d9c60fd4480d1c1e73dd819a9a67f1078815dce9d'

fail() {
    printf 'smoke-test: %s\n' "$*" >&2
    exit 1
}

stop_server() {
    local pid="${1:-}"
    case "$pid" in
        ''|*[!0-9]*) return ;;
    esac
    if kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
}

smoke_parent="$(cd -- "${TMPDIR:-/tmp}" && pwd -P)"
smoke_root="$(mktemp -d "${smoke_parent}/te4g-user-smoke.XXXXXX")"
recipe_server_pid=''
app_server_pid=''

cleanup() {
    stop_server "$app_server_pid"
    stop_server "$recipe_server_pid"

    local actual_parent basename
    actual_parent="$(dirname -- "$smoke_root")"
    basename="$(basename -- "$smoke_root")"
    if [[ "$actual_parent" != "$smoke_parent" || "$basename" != te4g-user-smoke.* ]]; then
        printf 'smoke-test: refusing to clean unvalidated path %s\n' "$smoke_root" >&2
        return
    fi
    rm -rf -- "$smoke_root"
}
trap cleanup EXIT HUP INT TERM

[[ -n "${TE4G_TAILWIND_BINARY:-}" ]] || fail 'TE4G_TAILWIND_BINARY is required.'
[[ ! -L "$TE4G_TAILWIND_BINARY" ]] || fail 'TE4G_TAILWIND_BINARY must not be a symlink.'
[[ -f "$TE4G_TAILWIND_BINARY" && -x "$TE4G_TAILWIND_BINARY" ]] || fail 'TE4G_TAILWIND_BINARY must be a regular executable.'
tailwind_binary="$(cd -- "$(dirname -- "$TE4G_TAILWIND_BINARY")" && pwd -P)/$(basename -- "$TE4G_TAILWIND_BINARY")"
validated_tailwind="$(php -r '
require $argv[1]."/vendor/autoload.php";
echo Te4g\UserBundle\Tests\Fixtures\App\PinnedTailwindBinary::tailwind433()->validate($argv[2]);
' "$PROJECT_DIR" "$tailwind_binary")"
[[ "$validated_tailwind" == "$tailwind_binary" ]] || fail 'Tailwind validation returned an unexpected path.'
if [[ "$(uname -s)-$(uname -m)" == 'Darwin-arm64' ]]; then
    actual_sha256="$(php -r 'echo hash_file("sha256", $argv[1]);' "$tailwind_binary")"
    [[ "$actual_sha256" == "$TAILWIND_SHA256_DARWIN_ARM64" ]] || fail "Tailwind SHA-256 must be ${TAILWIND_SHA256_DARWIN_ARM64}."
fi

free_port() {
    php -r '
$server = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage);
if (false === $server) {
    fwrite(STDERR, $errorMessage."\n");
    exit(1);
}
$address = stream_socket_get_name($server, false);
fclose($server);
if (false === $address || false === $separator = strrpos($address, ":")) {
    exit(1);
}
echo substr($address, $separator + 1);
'
}

wait_for_url() {
    local url="$1" log="$2"
    local attempt
    for attempt in {1..50}; do
        if curl --fail --silent --show-error "$url" >/dev/null 2>&1; then
            return
        fi
        sleep 0.1
    done
    [[ ! -f "$log" ]] || sed -n '1,160p' "$log" >&2
    fail "server did not become ready at ${url}"
}

readonly APP_DIR="${smoke_root}/app"
readonly RECIPE_ENDPOINT_DIR="${smoke_root}/recipe-endpoint"
mkdir -p "$RECIPE_ENDPOINT_DIR"

printf 'Creating a clean Symfony 8.1 web application...\n'
composer create-project 'symfony/skeleton:8.1.*' "$APP_DIR" --no-interaction --no-progress
(
    cd -- "$APP_DIR"
    composer require webapp --no-interaction --no-progress
)

recipe_port="$(free_port)"
recipe_base_url="http://127.0.0.1:${recipe_port}"
php -r '
$manifestPath = $argv[1];
$configPath = $argv[2];
$targetDirectory = $argv[3];
$baseUrl = $argv[4];
$manifestJson = file_get_contents($manifestPath);
$config = file_get_contents($configPath);
if (false === $manifestJson || false === $config) {
    exit(1);
}
$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
$ref = sha1($manifestJson."\0".$config);
$index = [
    "aliases" => [],
    "recipes" => ["te4g/user-bundle" => ["0.1"]],
    "recipe-conflicts" => [],
    "versions" => [],
    "branch" => "local",
    "is_contrib" => true,
    "_links" => [
        "repository" => "localhost/te4g-recipes-contrib",
        "origin_template" => "{package}:{version}@localhost/te4g-recipes-contrib:local",
        "recipe_template" => $baseUrl."/{package_dotted}.{version}.json",
    ],
];
$recipe = [
    "manifests" => [
        "te4g/user-bundle" => [
            "manifest" => $manifest,
            "files" => [
                "config/packages/te4g_user.yaml" => [
                    "contents" => preg_split("/\\R/", $config),
                    "executable" => false,
                ],
            ],
            "ref" => $ref,
        ],
    ],
];
file_put_contents($targetDirectory."/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
file_put_contents($targetDirectory."/te4g.user-bundle.0.1.json", json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
' "$PROJECT_DIR/flex/manifest.json" "$PROJECT_DIR/flex/config/packages/te4g_user.yaml" "$RECIPE_ENDPOINT_DIR" "$recipe_base_url"

recipe_log="${smoke_root}/recipe-server.log"
php -S "127.0.0.1:${recipe_port}" -t "$RECIPE_ENDPOINT_DIR" >"$recipe_log" 2>&1 &
recipe_server_pid="$!"
wait_for_url "${recipe_base_url}/index.json" "$recipe_log"

printf 'Installing the local package through Composer Flex...\n'
(
    cd -- "$APP_DIR"
    composer config --json repositories.te4g "{\"type\":\"path\",\"url\":\"${PROJECT_DIR}\",\"options\":{\"symlink\":false,\"versions\":{\"te4g/user-bundle\":\"0.1.0\"}}}"
    composer config secure-http false
    composer config extra.symfony.allow-contrib true
    composer config --json extra.symfony.endpoint "[\"${recipe_base_url}/index.json\",\"flex://defaults\"]"
    composer require 'te4g/user-bundle:0.1.0' --with-all-dependencies --no-interaction --no-progress
)
stop_server "$recipe_server_pid"
recipe_server_pid=''

php -r '
$bundles = require $argv[1];
exit((["all" => true] === ($bundles["Te4g\\UserBundle\\Te4gUserBundle"] ?? null)) ? 0 : 1);
' "$APP_DIR/config/bundles.php" || fail 'Flex did not register Te4gUserBundle in all environments.'
php -r '
require $argv[1]."/vendor/autoload.php";
$config = Symfony\Component\Yaml\Yaml::parseFile($argv[1]."/config/packages/te4g_user.yaml");
exit((false === ($config["te4g_user"]["enabled"] ?? null)) ? 0 : 1);
' "$APP_DIR" || fail 'Flex did not copy the disabled bootstrap configuration.'
php -r '
$lock = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$recipe = $lock["te4g/user-bundle"]["recipe"] ?? null;
exit((is_array($recipe) && "localhost/te4g-recipes-contrib" === ($recipe["repo"] ?? null)) ? 0 : 1);
' "$APP_DIR/symfony.lock" || fail 'symfony.lock does not record the local Flex recipe.'
(
    cd -- "$APP_DIR"
    php bin/console te4g:user:install --help >/dev/null
)

mkdir -p "$APP_DIR/migrations" "$APP_DIR/src/Entity" "$APP_DIR/src/Repository" "$APP_DIR/translations"
php -r '
file_put_contents($argv[1], "security:\n    firewalls:\n        main:\n            lazy: true\n");
' "$APP_DIR/config/packages/security.yaml"
php -r '
require $argv[1]."/vendor/autoload.php";
$yaml = Symfony\Component\Yaml\Yaml::dump(["symfonycasts_tailwind" => ["binary" => $argv[2]]], 3, 4);
file_put_contents($argv[1]."/config/packages/zz_te4g_smoke_tailwind.yaml", $yaml);
' "$APP_DIR" "$tailwind_binary"
php -r '
file_put_contents($argv[1], "app_home:\n    path: /\n    controller: Symfony\\Bundle\\FrameworkBundle\\Controller\\TemplateController\n    defaults:\n        template: base.html.twig\n");
' "$APP_DIR/config/routes/smoke_home.yaml"

export APP_ENV=dev
export DATABASE_URL='sqlite:///%kernel.project_dir%/var/smoke.db'
export MAILER_DSN='null://null'
export TE4G_TAILWIND_BINARY="$tailwind_binary"

printf 'Running the one-command installer...\n'
if ! install_output="$(cd -- "$APP_DIR" && php bin/console te4g:user:install --no-interaction)"; then
    printf '%s\n' "$install_output" >&2
    fail 'installer failed in the clean Symfony application.'
fi
printf '%s\n' "$install_output"
[[ "$install_output" == *'Generated Doctrine migration for review.'* ]] || fail 'installer did not report a generated migration.'
[[ "$install_output" != *'doctrine:migrations:migrate'* ]] || fail 'installer output unexpectedly references migration execution.'

shopt -s nullglob
migrations=("$APP_DIR"/migrations/Version*.php)
shopt -u nullglob
[[ "${#migrations[@]}" -eq 1 ]] || fail "expected exactly one generated migration, found ${#migrations[@]}."
php -r '
$database = $argv[1];
if (!is_file($database)) {
    exit(0);
}
$pdo = new PDO("sqlite:".$database);
$userTables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = \"table\" AND name = \"user\"")->fetchColumn();
if (0 !== $userTables) {
    fwrite(STDERR, "the generated user migration was executed\n");
    exit(1);
}
$metadataTables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = \"table\" AND name = \"doctrine_migration_versions\"")->fetchColumn();
if (1 === $metadataTables && 0 !== (int) $pdo->query("SELECT COUNT(*) FROM doctrine_migration_versions")->fetchColumn()) {
    fwrite(STDERR, "a migration was recorded as executed\n");
    exit(1);
}
' "$APP_DIR/var/smoke.db"

repeat_output="$(cd -- "$APP_DIR" && php bin/console te4g:user:install --no-interaction)"
[[ "$repeat_output" == *'Already installed; no changes required.'* ]] || fail 'second installer run was not a no-op.'

printf 'Checking boot, routes, assets, and offline icons...\n'
(
    cd -- "$APP_DIR"
    php bin/console lint:container --no-interaction
    php bin/console lint:twig templates --no-interaction
    php bin/console lint:yaml config --no-interaction
    php bin/console tailwind:build --no-interaction
)
[[ -s "$APP_DIR/var/tailwind/app.built.css" ]] || fail 'Tailwind output is missing or empty.'
for route in \
    te4g_user_register te4g_user_verify_email te4g_user_verify_resend \
    te4g_user_login te4g_user_login_link_request te4g_user_logout \
    te4g_user_login_link_check te4g_user_password_request \
    te4g_user_password_reset te4g_user_check_email te4g_user_account \
    te4g_user_profile te4g_user_email_change_confirm \
    te4g_user_password_change te4g_user_account_delete
do
    (cd -- "$APP_DIR" && php bin/console debug:router "$route" --show-controllers >/dev/null)
done

expected_icons=(circle-check eye eye-off key-round mail trash-2 triangle-alert)
shopt -s nullglob
icon_files=("$APP_DIR"/assets/icons/lucide/*.svg)
shopt -u nullglob
[[ "${#icon_files[@]}" -eq "${#expected_icons[@]}" ]] || fail 'offline Lucide icon inventory is not exact.'
for icon in "${expected_icons[@]}"; do
    icon_path="$APP_DIR/assets/icons/lucide/${icon}.svg"
    [[ -f "$icon_path" && ! -L "$icon_path" && -s "$icon_path" ]] || fail "invalid offline icon ${icon}."
done

printf 'Checking public HTTP flows...\n'
app_port="$(free_port)"
app_base_url="http://127.0.0.1:${app_port}"
app_log="${smoke_root}/app-server.log"
(
    cd -- "$APP_DIR"
    php -S "127.0.0.1:${app_port}" -t public public/index.php
) >"$app_log" 2>&1 &
app_server_pid="$!"
wait_for_url "${app_base_url}/login" "$app_log"

assert_http_status() {
    local path="$1" expected="$2"
    local response_file="${smoke_root}/http-response.html"
    local actual
    actual="$(curl --silent --show-error --output "$response_file" --write-out '%{http_code}' "${app_base_url}${path}")"
    [[ "$actual" == "$expected" ]] || {
        sed -n '1,80p' "$response_file" >&2
        fail "${path} returned ${actual}, expected ${expected}."
    }
}

assert_http_status / 200
assert_http_status /register 200
assert_http_status /login 200
assert_http_status /forgot-password 200
assert_http_status /check-email 200
assert_http_status /account 302

stop_server "$app_server_pid"
app_server_pid=''

php -r 'file_put_contents($argv[1], "\n{# application edit #}\n", FILE_APPEND);' "$APP_DIR/templates/bundles/Te4gUserBundle/security/login.html.twig"
if conflict_output="$(cd -- "$APP_DIR" && php bin/console te4g:user:install --no-interaction 2>&1)"; then
    fail 'installer replaced an application-modified generated UI file.'
fi
[[ "$conflict_output" == *'modified-managed-file'* ]] || fail 'modified generated UI did not produce the expected conflict.'

printf 'Smoke test passed.\n'
