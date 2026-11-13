MonteFiskal is a client for Montenegro's EFI fiscalization service (.NET 10, PHP 8.5, WooCommerce). Follow
`AGENTS.md` at the repository root: implement fiscal behaviour only where `docs/analysis/business-rules.md` has a
rule and quote its ID; keep .NET and PHP in parity through `test-vectors/`; use decimal money; never add
certificates, keys, passwords or real taxpayer data; never call the production endpoint from tests or samples.
