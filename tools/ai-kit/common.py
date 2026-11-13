"""Shared helpers of the assistant-kit tools. Standard library only."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RULES_FILE = ROOT / "docs/analysis/business-rules.md"
QUESTIONS_FILE = ROOT / "docs/analysis/open-questions.md"


def version() -> str:
    m = re.search(r"<Version>([^<]+)</Version>", (ROOT / "Directory.Build.props").read_text(encoding="utf-8"))
    if not m:
        raise SystemExit("version not found in Directory.Build.props")
    return m.group(1)


def rules() -> dict[str, str]:
    """Rule ID -> rule text (second table column), from the rule book."""
    out: dict[str, str] = {}
    for line in RULES_FILE.read_text(encoding="utf-8").splitlines():
        m = re.match(r"^\| (BR-[A-Z]+\d+) \| (.*?) \|(?: [^|]*\|)?\s*$", line)
        if m:
            out[m.group(1)] = m.group(2).strip()
    return out


def questions() -> set[str]:
    return set(re.findall(r"\bQ-\d+\b", QUESTIONS_FILE.read_text(encoding="utf-8")))


def error_codes() -> list[dict]:
    return json.loads((ROOT / "data/error-codes.json").read_text(encoding="utf-8"))["codes"]
