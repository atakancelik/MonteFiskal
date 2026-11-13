"""Tests for the secret scanner. Fixtures are built at run time so that no key-like text is committed."""

from __future__ import annotations

import base64
import os
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import secret_scan  # noqa: E402

D = "-" * 5


class SecretScanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.dir = Path(tempfile.mkdtemp())

    def tearDown(self) -> None:
        shutil.rmtree(self.dir)

    def write(self, name: str, content: str | bytes) -> Path:
        p = self.dir / name
        if isinstance(content, bytes):
            p.write_bytes(content)
        else:
            p.write_text(content, encoding="utf-8")
        return p

    def test_clean_file(self) -> None:
        self.assertEqual(secret_scan.scan_file(self.write("a.txt", "nothing to see\n")), [])

    def test_private_key_block_is_found(self) -> None:
        p = self.write("k.txt", f"{D}BEGIN RSA PRIVATE KEY{D}\nAAAA\n")
        self.assertIn("private key block", secret_scan.scan_file(p)[0])

    def test_certificate_block_is_found(self) -> None:
        p = self.write("c.txt", f"{D}BEGIN CERTIFICATE{D}\n")
        self.assertTrue(secret_scan.scan_file(p))

    def test_public_key_is_allowed(self) -> None:
        p = self.write("pub.txt", f"{D}BEGIN PUBLIC KEY{D}\n")
        self.assertEqual(secret_scan.scan_file(p), [])

    def test_forbidden_extension(self) -> None:
        for ext in (".pfx", ".p12", ".pem", ".key", ".crt"):
            p = self.write("x" + ext, "x")
            self.assertTrue(any("forbidden file type" in f for f in secret_scan.scan_file(p)), ext)

    def test_hard_coded_password(self) -> None:
        p = self.write("cfg.php", "$config = ['pass" + "word' => 'hunter22'];\n")
        self.assertTrue(secret_scan.scan_file(p))

    def test_placeholder_password_is_allowed(self) -> None:
        p = self.write("cfg.md", "password: '<your certificate password>'\npassword = \"$PFX_PASSWORD\"\n")
        self.assertEqual(secret_scan.scan_file(p), [])

    def test_allow_marker(self) -> None:
        p = self.write("doc.md", f"{D}BEGIN CERTIFICATE{D} secret-scan: allow (documents the marker)\n")
        self.assertEqual(secret_scan.scan_file(p), [])

    def test_base64_der_container(self) -> None:
        blob = base64.b64encode(b"\x30\x82\x0a\x00" + os.urandom(80)).decode()
        if not blob.startswith("MII"):
            blob = "MIIK" + blob[4:]
        p = self.write("b.txt", f"value = {blob}\n")
        self.assertTrue(secret_scan.scan_file(p))

    def test_binary_pkcs12_marker(self) -> None:
        data = b"\x30\x82\x01\x00\x00" + bytes.fromhex("2a864886f70d010c0a01") + b"\x00" * 20
        p = self.write("blob.bin", data)
        self.assertTrue(secret_scan.scan_file(p))

    def test_walk_skips_vendor(self) -> None:
        (self.dir / "vendor").mkdir()
        self.write("vendor/x.pfx", "x")
        self.assertEqual([f for f in secret_scan.walk([self.dir]) if f.suffix == ".pfx"], [])


if __name__ == "__main__":
    unittest.main()
