#!/usr/bin/env python3
"""Compare the live EFI WSDL/XSD with the bundled revision (architecture.md section 5, "Drift detection").

Exit code 0: the live files match ``SHA256SUMS`` of the bundled revision.
Exit code 3: at least one digest differs; a Markdown report is written to ``--report``
             (used by the scheduled ``schema-drift`` workflow to open a "spec-change" issue).
Exit code 2: the live service could not be reached (not a drift).

The live files are downloaded into a temporary directory and never written into the tree.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import tempfile
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from generate_catalog import dumps, generate  # noqa: E402

FILES = ("FiscalizationService.wsdl", "eficg-fiscalization-service.xsd", "xmldsig-core-schema.xsd")
DEFAULT_BASE = "https://efitest.tax.gov.me/fs-v1/"


def read_sums(path: Path) -> dict[str, str]:
    sums = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            digest, name = line.split(maxsplit=1)
            sums[name.strip()] = digest
    return sums


def fetch(url: str, timeout: float) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": "montefiskal-schema-drift"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:  # noqa: S310 (fixed https URL)
        return resp.read()


def diff_catalogs(old: dict, new: dict) -> list[str]:
    lines = []
    for msg in sorted(set(old["messages"]) | set(new["messages"])):
        a = {n["xpath"]: n for n in old["messages"].get(msg, [])}
        b = {n["xpath"]: n for n in new["messages"].get(msg, [])}
        for xp in sorted(set(a) - set(b)):
            lines.append(f"- removed `{xp}`")
        for xp in sorted(set(b) - set(a)):
            lines.append(f"- added `{xp}`")
        for xp in sorted(set(a) & set(b)):
            if a[xp] != b[xp]:
                changed = sorted(k for k in set(a[xp]) | set(b[xp]) if a[xp].get(k) != b[xp].get(k))
                lines.append(f"- changed `{xp}`: {', '.join(changed)}")
    return lines


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--revision", type=Path, default=Path("schemas/fs-v1/2026-11"))
    p.add_argument("--base-url", default=DEFAULT_BASE)
    p.add_argument("--report", type=Path, default=None)
    p.add_argument("--timeout", type=float, default=30.0)
    p.add_argument("--local-dir", type=Path, default=None,
                   help="compare files from this directory instead of downloading (tests)")
    args = p.parse_args(argv)

    sums = read_sums(args.revision / "SHA256SUMS")
    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        try:
            for name in FILES:
                data = (args.local_dir / name).read_bytes() if args.local_dir else fetch(args.base_url + name, args.timeout)
                (tmpdir / name).write_bytes(data)
        except OSError as exc:
            print(f"live schema not reachable: {exc}", file=sys.stderr)
            return 2

        changed = []
        for name in FILES:
            live = hashlib.sha256((tmpdir / name).read_bytes()).hexdigest()
            if live != sums.get(name):
                changed.append((name, sums.get(name), live))
        if not changed:
            print("no drift: live files match", args.revision)
            return 0

        report = ["## Live EFI schema differs from the bundled revision", "",
                  f"Bundled revision: `{args.revision.as_posix()}`", f"Source: {args.base_url}", "",
                  "| File | Bundled SHA-256 | Live SHA-256 |", "|---|---|---|"]
        report += [f"| `{n}` | `{old}` | `{new}` |" for n, old, new in changed]
        try:
            old_cat = json.loads((args.revision / "message-catalog.json").read_text(encoding="utf-8"))
            new_cat = json.loads(dumps(generate(tmpdir / "eficg-fiscalization-service.xsd",
                                                tmpdir / "FiscalizationService.wsdl", "live")))
            lines = diff_catalogs(old_cat, new_cat)
            report += ["", "### Catalog diff", ""] + (lines or ["(no node-level change; documentation or formatting only)"])
        except Exception as exc:  # noqa: BLE001 - the report must be written even if parsing fails
            report += ["", f"Catalog could not be regenerated from the live files: `{exc}`"]
        report += ["", "Next steps: see CONTRIBUTING.md, \"Handling a specification change\"."]
        text = "\n".join(report) + "\n"
        if args.report:
            args.report.write_text(text, encoding="utf-8")
        print(text)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
