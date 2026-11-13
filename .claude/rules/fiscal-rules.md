---
paths:
  - "src/**"
  - "plugins/**"
  - "test-vectors/**"
---

# Fiscal code

- Evidence rule: implement a fiscal behaviour only when `docs/analysis/business-rules.md` has a rule for it.
  Put the rule ID in the code comment and in the test name (for example `..._BR_D5`). A missing rule is
  reported, not invented; a rule marked UNVERIFIED becomes a setting with a documented default and a warning,
  never a hard error.
- Never apply Croatian, Serbian or Albanian fiscalization rules; the rule book is the only source.
- Parity: a change in `src/dotnet` has its counterpart in `src/php/montefiskal-core` and a shared vector in
  `test-vectors/`. Both suites must pass the same vector IDs.
- Money and quantities are `decimal` in .NET and decimal strings with bcmath in PHP. No `double` or `float`.
- An issued invoice keeps its number and IKOF forever; a resend changes only the header (BR-O7).
