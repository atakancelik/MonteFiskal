# Using MonteFiskal with an AI coding assistant

This folder is for developers who let an assistant (Claude Code, Cursor, GitHub Copilot, Codex and others) integrate
MonteFiskal into **their own** project. Contributors to MonteFiskal itself read `AGENTS.md` at the repository root.

| File | Use it for |
|---|---|
| [`context.md`](context.md) | The single-file briefing. Attach or paste it when the assistant cannot browse. Generated; do not edit. |
| [`questions.md`](questions.md) | The intake questions the assistant must ask before writing code. |
| [`prompts/`](prompts/README.md) | Ready-made tasks, one file each, with the verification the assistant must show you. |
| [`troubleshooting.md`](troubleshooting.md) | Error code, meaning, whether a retry is allowed, what to do. Generated. |
| [`integrator-kit/`](integrator-kit/) | Files you copy into your project so the assistant loads the rules on its own. |
| [`../../llms.txt`](../../llms.txt) | Index for assistants that can fetch URLs. |

## Per tool

| Tool | Copy | To |
|---|---|---|
| Any tool that reads `AGENTS.md` (Codex, Cursor, Copilot, Jules and others) | [`integrator-kit/AGENTS.snippet.md`](integrator-kit/AGENTS.snippet.md) | Append to the `AGENTS.md` of your project |
| Cursor | [`integrator-kit/cursor/montefiskal.mdc`](integrator-kit/cursor/montefiskal.mdc) | `.cursor/rules/montefiskal.mdc` |
| Claude Code | [`integrator-kit/claude/skills/montefiskal-integration/SKILL.md`](integrator-kit/claude/skills/montefiskal-integration/SKILL.md) | `.claude/skills/montefiskal-integration/SKILL.md` |
| A chat assistant without file access | [`context.md`](context.md) and one prompt | Paste both |

MonteFiskal never writes into another project's files; you copy what you need.

## Ground rules for the assistant

1. It asks before it assumes: every code and number comes from you or your SEP account, never from an example.
2. It quotes rule IDs (`BR-…`) instead of reasoning about tax law, and says so when a rule is not officially settled.
3. Test environment first; production only after the [go-live checklist](prompts/go-live.md).
4. Never paste a certificate, a key or a password into a chat. Tell the assistant where the application reads them from.
5. Every prompt ends with a verification you can see, such as a test run against the fake service.
