@AGENTS.md

## Claude Code

- Use plan mode for changes under `src/**/Security`, `src/**/Wire` and the plugin's sequence allocator
  (`plugins/montefiskal-woocommerce/src/Storage/WpdbOutboxStore.php`).
- The integration suite against efitest needs a certificate that is not in this repository; do not
  try to obtain or generate one for it. Run it only when the developer says the secrets are set.
- Path-scoped rules live in `.claude/rules/`.
