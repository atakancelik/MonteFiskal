#!/usr/bin/env bash
# Runs the cross-signature check locally or in CI: a fresh key pair is generated for this run only.
# Needs: openssl, dotnet, php (with bcmath, dom, openssl). Usage: tools/cross-signature/run.sh [workdir]
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="${1:-$(mktemp -d)}"
mkdir -p "$work/dotnet" "$work/php"
openssl req -x509 -newkey rsa:2048 -sha256 -days 2 -nodes -subj "/CN=MonteFiskal Cross Signature Test Only/serialNumber=12345678/O=Test Only/C=ME" \
  -keyout "$work/key.pem" -out "$work/cert.pem" 2>/dev/null
cp "$work/cert.pem" "$work/dotnet/cert.pem"
cp "$work/cert.pem" "$work/php/cert.pem"
dotnet run --project "$root/tools/cross-signature/dotnet" -c Release -- sign "$work/cert.pem" "$work/key.pem" "$work/dotnet"
php "$root/tools/cross-signature/php/cross-signature.php" sign "$work/cert.pem" "$work/key.pem" "$work/php"
# .NET signs, PHP verifies; PHP signs, .NET verifies.
php "$root/tools/cross-signature/php/cross-signature.php" verify "$work/dotnet"
dotnet run --project "$root/tools/cross-signature/dotnet" -c Release --no-build -- verify "$work/php"
# Deterministic RSA PKCS#1 v1.5: identical canonical bytes and identical signatures.
diff -r "$work/dotnet" "$work/php"
echo "cross-signature: identical bytes in both languages"
rm -f "$work/key.pem"
