# MonteFiskal

Open-source client for Montenegro's EFI fiscalization service: a .NET 10 library, a PHP 8.5 core
with the same API, and a WooCommerce plugin. There is no hosted service.

## Layout

```
src/dotnet/                        .NET packages             tests/dotnet/
src/php/montefiskal-core/          PHP core                  tests/php/
plugins/montefiskal-woocommerce/   WooCommerce plugin        plugins/montefiskal-woocommerce/tests/
test-vectors/                      shared vectors; both languages must pass the same IDs
schemas/fs-v1/                     message catalog and the official schema, unmodified
data/                              error catalog shared by both languages
docs/analysis/                     the specification this code implements; rule IDs (BR-…) come from here
docs/ai/                           material for assistants that integrate MonteFiskal into other projects
tools/                             generators, vector checks, cross-signature check, plugin packaging
```

## Commands

```sh
# .NET (SDK from global.json)
dotnet build MonteFiskal.slnx -c Release
dotnet test --project tests/dotnet/MonteFiskal.Tests -c Release
dotnet test --project tests/dotnet/MonteFiskal.IntegrationTests -c Release   # efitest: skips without secrets

# PHP core (PHP 8.5 with bcmath, dom, openssl, mbstring)
composer install
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=1G

# Shared vectors and parity
python3 -I tools/vectors/check_vectors.py --repo .
MONTEFISKAL_VECTOR_LOG=/tmp/dotnet.log dotnet test --project tests/dotnet/MonteFiskal.Tests -c Release
MONTEFISKAL_VECTOR_LOG=/tmp/php.log vendor/bin/phpunit
python3 -I tools/vectors/parity.py /tmp/dotnet.log /tmp/php.log
tools/cross-signature/run.sh /tmp/cross-signature

# WooCommerce plugin (needs MariaDB or MySQL; see the script for the variables)
WP_ROOT=/tmp/wordpress plugins/montefiskal-woocommerce/tests/install-wp.sh
WP_ROOT=/tmp/wordpress vendor/bin/phpunit -c plugins/montefiskal-woocommerce/phpunit.xml.dist
tools/plugin/build-zip.sh dist

# Assistant kit
python3 -I tools/ai-kit/generate.py && python3 -I tools/ai-kit/lint.py
```

## Rules

1. Never add a certificate, private key, PKCS#12 file, password or real taxpayer data. Tests generate
   keys at run time. CI rejects such files.
2. Evidence rule: a fiscal behaviour is implemented only if `docs/analysis/business-rules.md` has a rule
   for it. Quote the rule ID in the code comment and in the test name. If the rule is missing or marked
   UNVERIFIED, stop and say so; do not fill the gap from general knowledge or from another country's
   system.
3. Parity rule: a behaviour change lands in .NET and PHP together, with a shared test vector.
4. Do not edit files under `schemas/**/official/`. A schema change is a new revision (see
   `docs/analysis/architecture.md`, "Versioned adapter layer").
5. Money is decimal, never floating point. Rounding follows BR-A5 to BR-A7.
6. No call to the production endpoint from tests, samples or CI.
7. Do not copy code from other fiscalization projects.
8. `docs/ai/context.md` and `docs/ai/troubleshooting.md` are generated; edit `tools/ai-kit/` instead.

## Before you finish

Run the unit, vector and fake-service suites for every language you touched. For a change to XML
output, show the canonical bytes diff of the affected vectors. A release note states
"Fiscal behaviour changed: yes" or "no" (BR-R7).
