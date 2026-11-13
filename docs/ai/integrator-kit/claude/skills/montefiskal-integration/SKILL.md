---
name: montefiskal-integration
description: Integrate or troubleshoot MonteFiskal (Montenegro EFI fiscalization) in a .NET, PHP or WooCommerce project
---

# MonteFiskal integration

Targets MonteFiskal 0.1.0. Source: https://github.com/atakancelik/MonteFiskal/tree/main/docs/ai

1. Read the briefing: https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/docs/ai/context.md
2. Ask the intake questions from https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/docs/ai/questions.md,
   in order, skipping what the project already answers. Stop at a stop condition (no Montenegrin PIB, unsupported
   runtime, certificate only on a token for an unattended server, wish to skip the test environment, a certificate
   taken from another repository) and say why.
3. Pick one prompt by the answers and follow it:
   - .NET, new: `dotnet-first-invoice.md`; .NET point of sale: `dotnet-existing-pos.md`
   - PHP: `php-first-invoice.md`
   - WooCommerce: `woocommerce-setup.md`, theme work: `woocommerce-customize.md`
   - refunds: `corrective-invoice.md`; pre-orders: `advance-flow.md`; outages: `offline-and-resend.md`
   - an error code: `diagnose-error.md`; a new release: `upgrade.md`; going live: `go-live.md`
   Prompts: https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/docs/ai/prompts/README.md
4. End with that prompt's verification and show its output.

Never ask for a certificate, a key or a password. Never invent codes or tax numbers. Never call production before the
go-live checklist is complete. Use Montenegro's rule book only; the developer's accountant decides Q-24, Q-28 and Q-29.
