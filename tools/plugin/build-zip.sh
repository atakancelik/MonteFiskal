#!/usr/bin/env bash
# Builds the installable plugin zip: the plugin plus the PHP core, the shared data files and the unmodified
# official schemas under lib/montefiskal/. Tests and development files are left out.
# Usage: tools/plugin/build-zip.sh [out-dir]   -> <out-dir>/montefiskal-woocommerce-<version>.zip
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
out="${1:-$root/dist}"
version="$(sed -n "s/^const MONTEFISKAL_WC_VERSION = '\(.*\)';/\1/p" "$root/plugins/montefiskal-woocommerce/montefiskal-woocommerce.php")"
[ -n "$version" ] || { echo "version not found" >&2; exit 1; }
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
dest="$work/montefiskal-woocommerce"
mkdir -p "$dest/lib/montefiskal/src/php/montefiskal-core"
(cd "$root/plugins/montefiskal-woocommerce" && tar --exclude=tests --exclude=phpunit.xml.dist --exclude=composer.json -cf - .) | tar -xf - -C "$dest"
cp -R "$root/src/php/montefiskal-core/src" "$dest/lib/montefiskal/src/php/montefiskal-core/"
cp "$root/src/php/montefiskal-core/LICENSE" "$dest/lib/montefiskal/LICENSE" 2>/dev/null || cp "$root/LICENSE-MIT" "$dest/lib/montefiskal/LICENSE" 2>/dev/null || true
cp -R "$root/data" "$dest/lib/montefiskal/data"
cp -R "$root/schemas" "$dest/lib/montefiskal/schemas"
rm -rf "$dest/lib/montefiskal/src/php/montefiskal-core/src/Testing"
mkdir -p "$out"
out="$(cd "$out" && pwd)"   # absolute, because the zip is written from a temporary folder
zip_path="$out/montefiskal-woocommerce-$version.zip"
rm -f "$zip_path"
(cd "$work" && php -r '
  $zip = new ZipArchive();
  $zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || exit(1);
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("montefiskal-woocommerce", FilesystemIterator::SKIP_DOTS));
  $files = [];
  foreach ($it as $f) { $files[] = $f->getPathname(); }
  sort($files);
  foreach ($files as $f) { $zip->addFile($f, $f); $zip->setMtimeName($f, 315532800); }
  $zip->close();' "$zip_path")
(cd "$out" && sha256sum "$(basename "$zip_path")" > "$(basename "$zip_path").sha256")
echo "$zip_path"
