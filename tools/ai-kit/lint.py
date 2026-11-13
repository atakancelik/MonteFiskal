#!/usr/bin/env python3
"""Lints the assistant kit (ai-assistant-kit.md section 9, items 1, 4 and 6).

Checks: every file of section 3 exists; CLAUDE.md starts with @AGENTS.md; Cursor rules are .mdc with valid front
matter; the Claude rule and skill have front matter; llms.txt has one H1, a blockquote, then only H2 link lists;
no realistic-looking taxpayer number, code or key in any example (placeholders and the fake-service values only);
every BR- and Q- reference resolves; every relative link and every link into this repository resolves; every
prompt names the current version and has a verification section.

Usage: python3 -I tools/ai-kit/lint.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from common import ROOT, questions, rules, version  # noqa: E402

REPO_URLS = (
    "https://raw.githubusercontent.com/atakancelik/MonteFiskal/main/",
    "https://github.com/atakancelik/MonteFiskal/blob/main/",
    "https://github.com/atakancelik/MonteFiskal/tree/main/",
)
REQUIRED = [
    "AGENTS.md",
    "CLAUDE.md",
    ".claude/rules/fiscal-rules.md",
    ".claude/rules/secrets.md",
    ".cursor/rules/montefiskal-contributing.mdc",
    ".github/copilot-instructions.md",
    "llms.txt",
    "docs/ai/README.md",
    "docs/ai/context.md",
    "docs/ai/questions.md",
    "docs/ai/troubleshooting.md",
    "docs/ai/integrator-kit/AGENTS.snippet.md",
    "docs/ai/integrator-kit/cursor/montefiskal.mdc",
    "docs/ai/integrator-kit/claude/skills/montefiskal-integration/SKILL.md",
]
PROMPTS = [
    "dotnet-first-invoice", "dotnet-existing-pos", "php-first-invoice", "woocommerce-setup", "woocommerce-customize",
    "corrective-invoice", "advance-flow", "offline-and-resend", "go-live", "diagnose-error", "upgrade",
    "spec-change-report",
]
# Values that belong to the fake service and the test vectors; anything else that looks like a real value fails.
FAKE_VALUES = {
    "12345678", "00000000", "1234567890123", "bb123bb123", "cc123cc123", "ss123ss123", "oo123oo123", "mm123mm123",
    "03012345",
}
CODE = re.compile(r"\b[a-z]{2}\d{3}[a-z]{2}\d{3}\b")
TIN = re.compile(r"(?<![\w./:#-])(\d{8}|\d{13})(?![\w./:-])")
SECRET = re.compile(r"-----BEGIN [A-Z ]*(PRIVATE KEY|CERTIFICATE)-----|MII[A-Za-z0-9+/]{60,}")
REF = re.compile(r"\b(BR-[A-Z]+\d+|Q-\d+)\b")
LINK = re.compile(r"\[[^\]]*\]\(([^)\s]+)\)")
URL = re.compile(r"https://[^\s)>\"'`]+")


def kit_files() -> list[Path]:
    files = [ROOT / p for p in REQUIRED if (ROOT / p).is_file()]
    files += sorted((ROOT / "docs/ai").rglob("*.md")) + sorted((ROOT / "docs/ai").rglob("*.mdc"))
    files += sorted((ROOT / "tools/ai-kit/samples").rglob("*.*"))
    for extra in ("README.md", "src/dotnet/README.md", "docs/api/dotnet.md", "docs/api/php.md", "docs/setup-guide.md",
                  "plugins/montefiskal-woocommerce/readme.txt"):
        if (ROOT / extra).is_file():
            files.append(ROOT / extra)
    return list(dict.fromkeys(files))


def front_matter(text: str) -> dict[str, str] | None:
    m = re.match(r"^---\n(.*?)\n---\n", text, re.S)
    if not m:
        return None
    out: dict[str, str] = {}
    key = None
    for line in m.group(1).splitlines():
        km = re.match(r"^([A-Za-z_-]+):\s*(.*)$", line)
        if km:
            key = km.group(1)
            out[key] = km.group(2).strip()
        elif key and line.strip().startswith("- "):
            out[key] = (out[key] + " " + line.strip()[2:]).strip()
    return out


def check_formats(err: list[str]) -> None:
    for p in REQUIRED:
        if not (ROOT / p).is_file():
            err.append(f"missing kit file: {p}")
    for name in PROMPTS:
        if not (ROOT / f"docs/ai/prompts/{name}.md").is_file():
            err.append(f"missing prompt: docs/ai/prompts/{name}.md")
    claude = ROOT / "CLAUDE.md"
    if claude.is_file() and claude.read_text(encoding="utf-8").splitlines()[:1] != ["@AGENTS.md"]:
        err.append("CLAUDE.md: first line must be @AGENTS.md")
    for mdc in [*ROOT.joinpath(".cursor/rules").glob("*"), *ROOT.joinpath("docs/ai/integrator-kit/cursor").glob("*")]:
        rel = mdc.relative_to(ROOT)
        if mdc.suffix != ".mdc":
            err.append(f"{rel}: Cursor rules must use the .mdc extension")
            continue
        fm = front_matter(mdc.read_text(encoding="utf-8"))
        if fm is None or "description" not in fm or fm.get("alwaysApply") not in ("true", "false"):
            err.append(f"{rel}: front matter needs description and alwaysApply: true|false")
        elif "integrator-kit" in str(rel) and fm["alwaysApply"] != "false":
            err.append(f"{rel}: the integrator rule must have alwaysApply: false")
        elif "integrator-kit" not in str(rel) and fm["alwaysApply"] != "true":
            err.append(f"{rel}: the contributor rule must have alwaysApply: true")
        if len(mdc.read_text(encoding="utf-8").splitlines()) > 500:
            err.append(f"{rel}: keep Cursor rules under 500 lines")
    fm = front_matter((ROOT / ".claude/rules/fiscal-rules.md").read_text(encoding="utf-8")) if (ROOT / ".claude/rules/fiscal-rules.md").is_file() else None
    if fm is None or not fm.get("paths"):
        err.append(".claude/rules/fiscal-rules.md: front matter needs paths:")
    skill = ROOT / "docs/ai/integrator-kit/claude/skills/montefiskal-integration/SKILL.md"
    if skill.is_file():
        fm = front_matter(skill.read_text(encoding="utf-8"))
        if fm is None or not fm.get("description"):
            err.append(f"{skill.relative_to(ROOT)}: front matter needs a description")
        elif len(fm["description"]) > 1024:
            err.append(f"{skill.relative_to(ROOT)}: description too long")
    if (ROOT / "llms.txt").is_file():
        err.extend(llms_txt((ROOT / "llms.txt").read_text(encoding="utf-8")))
    if claude.is_file() and len(claude.read_text(encoding="utf-8").splitlines()) > 200:
        err.append("CLAUDE.md: keep it under 200 lines")


def llms_txt(text: str) -> list[str]:
    """One H1 first, then a blockquote, optional prose, then only H2 sections holding link list items."""
    err: list[str] = []
    lines = [line for line in text.splitlines() if line.strip()]
    if not lines or not lines[0].startswith("# "):
        return ["llms.txt: must start with one H1"]
    if sum(1 for line in lines if line.startswith("# ")) != 1:
        err.append("llms.txt: exactly one H1")
    if len(lines) < 2 or not lines[1].startswith(">"):
        err.append("llms.txt: the H1 must be followed by a blockquote")
    in_sections = False
    for line in lines[1:]:
        if line.startswith("## "):
            in_sections = True
        elif line.startswith("#"):
            err.append(f"llms.txt: only H2 headings allowed: {line}")
        elif in_sections and not re.match(r"^- \[[^\]]+\]\([^)]+\)(: .*)?$", line):
            err.append(f"llms.txt: only link list items allowed in sections: {line}")
    if not in_sections:
        err.append("llms.txt: no H2 link lists")
    return err


def check_values(path: Path, text: str, err: list[str]) -> None:
    rel = path.relative_to(ROOT)
    for n, line in enumerate(text.splitlines(), 1):
        if SECRET.search(line):
            err.append(f"{rel}:{n}: key or certificate material")
        for m in CODE.finditer(line):
            if m.group(0) not in FAKE_VALUES:
                err.append(f"{rel}:{n}: realistic-looking code {m.group(0)}; use a placeholder or a fake-service value")
        for m in TIN.finditer(line):
            if m.group(1) not in FAKE_VALUES and not re.match(r"20\d{6}$", m.group(1)):
                err.append(f"{rel}:{n}: realistic-looking tax number {m.group(1)}; use <your TIN> or 12345678")


def check_refs(path: Path, text: str, known_rules: set[str], known_q: set[str], err: list[str]) -> None:
    rel = path.relative_to(ROOT)
    for m in REF.finditer(text):
        ref = m.group(1)
        if (ref.startswith("BR-") and ref not in known_rules) or (ref.startswith("Q-") and ref not in known_q):
            err.append(f"{rel}: unknown reference {ref}")


def resolve(target: str, base: Path) -> Path | None:
    for prefix in REPO_URLS:
        if target.startswith(prefix):
            return ROOT / target[len(prefix):].split("#")[0].rstrip("/")
    if re.match(r"^[a-z]+:", target) or target.startswith("#"):
        return None
    return (base / target.split("#")[0]).resolve()


def check_links(path: Path, text: str, err: list[str]) -> None:
    rel = path.relative_to(ROOT)
    targets = {m.group(1) for m in LINK.finditer(text)} | {m.group(0).rstrip(".,;:") for m in URL.finditer(text)}
    for t in sorted(targets):
        if "<" in t or "{" in t:
            continue
        p = resolve(t, path.parent)
        if p is not None and not p.exists():
            err.append(f"{rel}: broken link {t}")


def check_prompts(err: list[str]) -> None:
    v = version()
    for p in sorted((ROOT / "docs/ai/prompts").glob("*.md")):
        text = p.read_text(encoding="utf-8")
        rel = p.relative_to(ROOT)
        if f"Targets MonteFiskal {v}" not in text:
            err.append(f"{rel}: must name the version (Targets MonteFiskal {v}); run tools/ai-kit/generate.py")
        if p.name != "README.md" and "## Verification" not in text:
            err.append(f"{rel}: must end with a verification section")


def lint() -> list[str]:
    err: list[str] = []
    check_formats(err)
    known_rules, known_q = set(rules()), questions()
    for path in kit_files():
        text = path.read_text(encoding="utf-8")
        check_values(path, text, err)
        check_refs(path, text, known_rules, known_q, err)
        if path.suffix in (".md", ".mdc", ".txt"):
            check_links(path, text, err)
    check_prompts(err)
    return err


def main() -> int:
    err = lint()
    for e in err:
        print(e, file=sys.stderr)
    print(f"ai-kit lint: {len(kit_files())} files, {len(err)} problems")
    return 1 if err else 0


if __name__ == "__main__":
    sys.exit(main())
