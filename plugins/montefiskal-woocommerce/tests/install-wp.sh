#!/usr/bin/env bash
# Installs a throw-away WordPress + WooCommerce site for the plugin integration tests (CI and local).
# Needs: php with mysqli, a reachable MySQL/MariaDB, wp-cli (WP_CLI=path to wp-cli.phar or "wp" on PATH).
# Env: WP_ROOT, DB_HOST, DB_NAME, DB_USER, DB_PASS, MONTEFISKAL_HPOS (1 or 0), WC_ZIP (optional local zip),
#      WP_VERSION (default latest), WC_VERSION (default latest stable).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
plugin="$(cd "$here/.." && pwd)"
: "${WP_ROOT:?}" "${DB_HOST:?}" "${DB_NAME:?}" "${DB_USER:?}"
DB_PASS="${DB_PASS:-}"
HPOS="${MONTEFISKAL_HPOS:-1}"
if [ -n "${WP_CLI:-}" ]; then wp=(php -d error_reporting=24575 "$WP_CLI"); else wp=(wp); fi
wp+=(--path="$WP_ROOT" --allow-root --quiet)

mkdir -p "$WP_ROOT"
if [ ! -f "$WP_ROOT/wp-load.php" ]; then
  if [ -n "${WP_TARBALL:-}" ]; then tar -xzf "$WP_TARBALL" -C "$WP_ROOT" --strip-components=1; else "${wp[@]}" core download --version="${WP_VERSION:-latest}"; fi
fi
DB_HOST="$DB_HOST" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" php -r '
  $h = getenv("DB_HOST"); $port = 3306;
  if (str_contains($h, ":")) { [$h, $port] = explode(":", $h); }
  $m = new mysqli($h, getenv("DB_USER"), getenv("DB_PASS"), "", (int) $port);
  $db = getenv("DB_NAME");
  $m->query("DROP DATABASE IF EXISTS `$db`"); $m->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");'
rm -f "$WP_ROOT/wp-config.php"
"${wp[@]}" config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check \
  --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', true );
define( 'DISABLE_WP_CRON', true );
PHP
"${wp[@]}" core install --url=http://example.test --title="MonteFiskal Test Only" --admin_user=admin \
  --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.test --skip-email
if [ -n "${WC_ZIP:-}" ]; then
  rm -rf "$WP_ROOT/wp-content/plugins/woocommerce"
  php -r '$z = new ZipArchive(); $z->open($argv[1]) === true || exit(1); $z->extractTo($argv[2]); $z->close();' "$WC_ZIP" "$WP_ROOT/wp-content/plugins"
else
  "${wp[@]}" plugin install woocommerce ${WC_VERSION:+--version="$WC_VERSION"}
fi
"${wp[@]}" plugin activate woocommerce
ln -sfn "$plugin" "$WP_ROOT/wp-content/plugins/montefiskal-woocommerce"
"${wp[@]}" plugin activate montefiskal-woocommerce
if [ "$HPOS" = "1" ]; then
  "${wp[@]}" wc hpos enable --ignore-plugin-compatibility
else
  "${wp[@]}" option update woocommerce_custom_orders_table_enabled no
fi
echo "WordPress $("${wp[@]}" core version), WooCommerce $("${wp[@]}" plugin get woocommerce --field=version), HPOS=$HPOS"
