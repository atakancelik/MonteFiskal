<!-- Append this block to the AGENTS.md of your project. Source: MonteFiskal docs/ai/integrator-kit/AGENTS.snippet.md -->

## Fiscalization (MonteFiskal, Montenegro EFI)

- Fiscalization uses the MonteFiskal library; never compute the IKOF, sign XML, number invoices or build the
  request yourself. Briefing: https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/docs/ai/context.md
- Never invent the TIN, business unit, TCR, software, maintainer or operator codes; ask the developer. They come
  from the company registration and the SEP portal.
- Never put a certificate, private key or password in the repository, a prompt, a log or an issue.
- Use the test environment and the fake service (`MonteFiskal.Testing`, `MonteFiskal\Testing`) until the go-live
  checklist is done; no production calls from tests.
- Money is decimal, never floating point. A consumer card payment is a cash invoice (BR-K3) and needs the day's
  initial cash deposit (BR-D5).
- An issued invoice is never edited, deleted or renumbered; corrections are corrective invoices (BR-C1, BR-O7).
- Use Montenegro's rules from the MonteFiskal rule book only, never Croatian, Serbian or Albanian rules. What is not
  officially settled (Q-24, Q-28, Q-29) is decided by the developer's accountant.
