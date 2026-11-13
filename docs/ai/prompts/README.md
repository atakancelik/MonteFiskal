# Prompts

> Targets MonteFiskal 0.1.0. Each prompt starts with the common frame and ends with a verification the assistant must show.

| Prompt | When to use |
|---|---|
| [dotnet-first-invoice.md](dotnet-first-invoice.md) | New .NET integration |
| [dotnet-existing-pos.md](dotnet-existing-pos.md) | A .NET point of sale that already prints receipts |
| [php-first-invoice.md](php-first-invoice.md) | Plain PHP 8.5 application |
| [woocommerce-setup.md](woocommerce-setup.md) | Installing and configuring the WooCommerce plugin |
| [woocommerce-customize.md](woocommerce-customize.md) | Changing templates or gateway mapping of the plugin |
| [corrective-invoice.md](corrective-invoice.md) | Refunds and mistakes |
| [advance-flow.md](advance-flow.md) | Pre-orders, deposits, strict reading of Q-24 |
| [offline-and-resend.md](offline-and-resend.md) | The service is slow or down |
| [go-live.md](go-live.md) | Before the first production invoice |
| [diagnose-error.md](diagnose-error.md) | An error code or fault string appeared |
| [upgrade.md](upgrade.md) | A new MonteFiskal release |
| [spec-change-report.md](spec-change-report.md) | The service started rejecting messages that used to be accepted |

The code blocks in these prompts are compiled and run in CI against the fake service (`tools/ai-kit/run_blocks.py`).
