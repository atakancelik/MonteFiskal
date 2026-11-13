#!/usr/bin/env python3
"""Structural check of test-vectors/: envelope fields, id = file name, kind = directory, known rule and
question IDs, and the schema revision of every vector is a bundled revision. Standard library only.

Usage: python3 tools/vectors/check_vectors.py [--repo .]
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

KINDS = {"iic", "url", "number", "time", "amounts", "xml", "validate", "fault", "response", "offline", "qr", "report"}
REQUIRED = ("id", "kind", "title", "source", "status", "schemaRevision", "input", "expected")
ALLOWED = set(REQUIRED) | {"rules", "openQuestions"}


def known_ids(repo: Path) -> tuple[set[str], set[str]]:
    rules = set(re.findall(r"\|\s*(BR-[A-Z]+[0-9]+[a-z]?)\s*\|", (repo / "docs/analysis/business-rules.md").read_text(encoding="utf-8")))
    questions = set(re.findall(r"\|\s*(Q-[0-9]{2})\s*\|", (repo / "docs/analysis/open-questions.md").read_text(encoding="utf-8")))
    return rules | {"SCHEMA"}, questions


def revisions(repo: Path) -> set[str]:
    out = set()
    for cat in (repo / "schemas").glob("fs-v*/*/message-catalog.json"):
        out.add(json.loads(cat.read_text(encoding="utf-8"))["meta"]["xsdSha256"])
    return out


def check(repo: Path) -> list[str]:
    errors: list[str] = []
    rules, questions = known_ids(repo)
    revs = revisions(repo)
    seen: set[str] = set()
    root = repo / "test-vectors"
    for path in sorted(root.glob("*/*.json")):
        if path.parent.name == "schema":
            continue
        rel = path.relative_to(repo)
        raw = path.read_bytes()
        if b"\r" in raw:
            errors.append(f"{rel}: CR characters (use LF)")
        try:
            v = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            errors.append(f"{rel}: not valid UTF-8 JSON ({exc})")
            continue
        for key in REQUIRED:
            if key not in v:
                errors.append(f"{rel}: missing '{key}'")
        for key in v:
            if key not in ALLOWED:
                errors.append(f"{rel}: unknown key '{key}'")
        if v.get("id") != path.stem:
            errors.append(f"{rel}: id '{v.get('id')}' differs from the file name")
        if v.get("kind") != path.parent.name or v.get("kind") not in KINDS:
            errors.append(f"{rel}: kind '{v.get('kind')}' does not match directory '{path.parent.name}'")
        if v.get("status") not in ("verified", "derived", "unverified"):
            errors.append(f"{rel}: bad status")
        if v.get("schemaRevision") not in revs:
            errors.append(f"{rel}: schemaRevision is not a bundled revision")
        for r in v.get("rules", []):
            if r not in rules:
                errors.append(f"{rel}: unknown rule {r}")
        for q in v.get("openQuestions", []):
            if q not in questions:
                errors.append(f"{rel}: unknown question {q}")
        if v.get("id") in seen:
            errors.append(f"{rel}: duplicate id")
        seen.add(v.get("id"))
    if not seen:
        errors.append("no vectors found")
    return errors


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[2])
    args = p.parse_args(argv)
    errors = check(args.repo)
    for e in errors:
        print(e)
    if errors:
        return 1
    print(f"test-vectors: {len(list((args.repo / 'test-vectors').glob('*/*.json'))) - 1} vectors OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
