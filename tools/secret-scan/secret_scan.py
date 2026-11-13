#!/usr/bin/env python3
"""Fail when the tree contains key material, certificates, PKCS#12 containers or passwords.

Project rule (CONTRIBUTING.md, SECURITY.md, analysis Q-19): no certificate, private key,
PKCS#12 file, password or real taxpayer data is ever committed. Tests generate keys at run
time. This scanner is the CI gate (`secret-scan` workflow) and a pre-commit helper.

Usage:
    python3 tools/secret-scan/secret_scan.py            # scan files tracked by git
    python3 tools/secret-scan/secret_scan.py PATH...    # scan the given files or directories

A line can be exempted with the marker ``secret-scan: allow`` and a reason on the same line;
use it only for documentation that names a pattern, never for a real value.
"""

from __future__ import annotations

import hashlib
import os
import re
import subprocess
import sys
from pathlib import Path

FORBIDDEN_EXTENSIONS = {
    ".pfx", ".p12", ".pkcs12", ".pem", ".key", ".crt", ".cer", ".der", ".jks", ".keystore",
    ".p7b", ".p7c", ".p8", ".pk8", ".csr", ".ppk", ".kdbx", ".gpg", ".asc",
}
# Known shared test certificate circulating in other projects (analysis test-strategy.md section 7).
FORBIDDEN_SHA256 = {"52c92c03973e081692627c4abf2a023231f7cc55fb3609c9e6869fa3e171f6f4"}

DASHES = "-----"
TEXT_RULES: list[tuple[str, re.Pattern[str]]] = [
    ("private key block", re.compile(DASHES + r"BEGIN ((RSA|EC|DSA|ENCRYPTED|OPENSSH) )?PRIVATE KEY" + DASHES)),
    ("certificate block", re.compile(DASHES + r"BEGIN (TRUSTED )?CERTIFICATE" + DASHES)),
    ("PKCS#7 block", re.compile(DASHES + r"BEGIN PKCS7" + DASHES)),
    ("hard-coded password", re.compile(
        r"""(?i)\b(password|passwd|passphrase|lozinka|sifra|pfx_?pass\w*)\b["']?\s*(=>|=|:)\s*["']"""
        r"""(?!<|\$|\{|%|\[|your|changeme|example|placeholder|secret-from-|env:)[^"'\s]{4,}["']""")),
    ("base64 PKCS#12 container", re.compile(r"\bMII[A-Za-z0-9+/]{60,}")),
]
# DER OIDs: pkcs-12 bag types (1.2.840.113549.1.12.10.1) and pkcs-12 PBE (1.2.840.113549.1.12.1)
BINARY_MARKERS = (bytes.fromhex("2a864886f70d010c0a01"), bytes.fromhex("2a864886f70d010c01"))
ALLOW_MARKER = "secret-scan: allow"
SKIP_DIRS = {".git", "node_modules", "vendor", "bin", "obj", "__pycache__", ".phpunit.cache"}


def tracked_files(root: Path) -> list[Path]:
    out = subprocess.run(["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
                         cwd=root, check=True, capture_output=True).stdout
    return [root / p for p in out.decode("utf-8").split("\0") if p]


def walk(paths: list[Path]) -> list[Path]:
    files = []
    for p in paths:
        if p.is_file():
            files.append(p)
        elif p.is_dir():
            for dirpath, dirnames, filenames in os.walk(p):
                dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
                files.extend(Path(dirpath) / f for f in filenames)
    return files


def scan_file(path: Path) -> list[str]:
    findings = []
    if path.suffix.lower() in FORBIDDEN_EXTENSIONS:
        findings.append(f"{path}: forbidden file type {path.suffix}")
    try:
        data = path.read_bytes()
    except OSError as exc:
        return [f"{path}: unreadable ({exc})"]
    if hashlib.sha256(data).hexdigest() in FORBIDDEN_SHA256:
        findings.append(f"{path}: known shared test certificate")
    if b"\0" in data[:8192]:
        if data[:1] == b"\x30" and any(m in data for m in BINARY_MARKERS):
            findings.append(f"{path}: looks like a PKCS#12 container")
        return findings
    text = data.decode("utf-8", errors="replace")
    for lineno, line in enumerate(text.splitlines(), 1):
        if ALLOW_MARKER in line:
            continue
        for name, rx in TEXT_RULES:
            if rx.search(line):
                findings.append(f"{path}:{lineno}: {name}")
    return findings


def main(argv: list[str]) -> int:
    root = Path.cwd()
    files = walk([Path(a) for a in argv]) if argv else tracked_files(root)
    findings = []
    for f in files:
        if f.exists():
            findings.extend(scan_file(f))
    for line in findings:
        print(line)
    if findings:
        print(f"\nsecret-scan: {len(findings)} finding(s). Never commit keys, certificates, PKCS#12 files or passwords.",
              file=sys.stderr)
        return 1
    print(f"secret-scan: {len(files)} files clean")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
