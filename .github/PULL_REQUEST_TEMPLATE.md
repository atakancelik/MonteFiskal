## What and why

<!-- One paragraph. Link the issue. -->

## Fiscal rules

<!-- Rule IDs (BR-…) this change implements or touches, or "none". -->

## Checklist

- [ ] No certificate, key, PKCS#12 file, password or real taxpayer data (`python3 tools/secret-scan/secret_scan.py`)
- [ ] Behaviour change lands in .NET **and** PHP with a shared vector in `test-vectors/` (parity rule)
- [ ] Every new fiscal behaviour cites its rule ID in a code comment and in the test name (evidence rule)
- [ ] `dotnet test` and `composer test` pass; plugin integration tests pass when the plugin changed
- [ ] Release note line "fiscal behaviour changed: yes/no" filled in `CHANGELOG.md`
- [ ] Docs and `docs/ai/` updated if public API or options changed (`python3 -I tools/ai-kit/generate.py && python3 -I tools/ai-kit/lint.py`)
