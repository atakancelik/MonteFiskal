"""Self-test of the assistant-kit linter: it must catch what it claims to catch."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import generate  # noqa: E402
import lint  # noqa: E402
from common import ROOT, rules  # noqa: E402

GOOD_LLMS = """# Name

> Summary.

Prose is allowed before the sections.

## Docs

- [A](https://example.org/a): first
- [B](https://example.org/b)
"""


class LlmsTxt(unittest.TestCase):
    def test_good_file_passes(self) -> None:
        self.assertEqual([], lint.llms_txt(GOOD_LLMS))

    def test_missing_blockquote_fails(self) -> None:
        self.assertTrue(lint.llms_txt(GOOD_LLMS.replace("> Summary.", "Summary.")))

    def test_second_h1_fails(self) -> None:
        self.assertTrue(lint.llms_txt(GOOD_LLMS + "\n# Again\n"))

    def test_prose_inside_a_section_fails(self) -> None:
        self.assertTrue(lint.llms_txt(GOOD_LLMS + "Some prose.\n"))

    def test_h3_fails(self) -> None:
        self.assertTrue(lint.llms_txt(GOOD_LLMS + "### Deeper\n"))


class Values(unittest.TestCase):
    def run_check(self, text: str) -> list[str]:
        err: list[str] = []
        lint.check_values(ROOT / "docs/ai/README.md", text, err)
        return err

    def test_fake_service_values_pass(self) -> None:
        self.assertEqual([], self.run_check('IssuerTin = "12345678", BusinessUnit = "bb123bb123", Tcr = "cc123cc123"'))

    def test_realistic_tin_fails(self) -> None:
        self.assertTrue(self.run_check('IssuerTin = "02876543"'))

    def test_realistic_code_fails(self) -> None:
        self.assertTrue(self.run_check('BusinessUnit = "xk482qz913"'))

    def test_private_key_fails(self) -> None:
        self.assertTrue(self.run_check("-" * 5 + "BEGIN " + "PRIVATE KEY" + "-" * 5))

    def test_dates_are_not_tax_numbers(self) -> None:
        self.assertEqual([], self.run_check("generated 20261113"))


class FrontMatter(unittest.TestCase):
    def test_reads_lists(self) -> None:
        fm = lint.front_matter("---\npaths:\n  - \"src/**\"\nalwaysApply: false\n---\nbody\n")
        self.assertEqual({"paths": '"src/**"', "alwaysApply": "false"}, fm)

    def test_missing_front_matter(self) -> None:
        self.assertIsNone(lint.front_matter("# no front matter\n"))


class Repository(unittest.TestCase):
    def test_rule_book_parses(self) -> None:
        self.assertGreater(len(rules()), 100)
        self.assertIn("BR-D5", rules())

    def test_generated_briefing_has_no_unresolved_template(self) -> None:
        self.assertNotIn("{{", generate.context())

    def test_unknown_reference_is_reported(self) -> None:
        err: list[str] = []
        lint.check_refs(ROOT / "docs/ai/README.md", "see BR-Z99 and Q-999", set(rules()), set(), err)
        self.assertEqual(2, len(err))


if __name__ == "__main__":
    unittest.main()
