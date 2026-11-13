"""Tests for the catalog generator and the drift check. Run: python3 -m unittest discover tools/catalog/tests"""

from __future__ import annotations

import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
TOOL = HERE.parent
REPO = TOOL.parent.parent
REVISION = REPO / "schemas" / "fs-v1" / "2026-11"
sys.path.insert(0, str(TOOL))

import drift_check  # noqa: E402
from generate_catalog import dumps, generate  # noqa: E402

MINI_XSD = """<?xml version="1.0" encoding="UTF-8"?>
<schema xmlns="http://www.w3.org/2001/XMLSchema" xmlns:me="urn:t" xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
        targetNamespace="urn:t" elementFormDefault="qualified">
  <element name="Root">
    <annotation><documentation> Root doc. </documentation></annotation>
    <complexType>
      <all>
        <element name="List" type="me:ListType" minOccurs="0">
          <unique name="U"><selector xpath=".//me:X"/><field xpath="@K"/></unique>
        </element>
        <element name="Code" type="me:CodeSType"/>
        <element ref="ds:Signature"/>
      </all>
      <attribute name="Id" type="string" fixed="Request" use="required"/>
    </complexType>
  </element>
  <complexType name="ListType">
    <sequence><element name="X" type="me:XType" maxOccurs="5"/></sequence>
  </complexType>
  <complexType name="XType">
    <simpleContent><extension base="string">
      <attribute name="K" type="me:KindSType" use="required"/>
      <attribute name="N" type="me:NameSType"/>
    </extension></simpleContent>
  </complexType>
  <simpleType name="KindSType"><restriction base="string">
    <enumeration value="A"><annotation><documentation>First</documentation></annotation></enumeration>
    <enumeration value="B-C"/>
  </restriction></simpleType>
  <simpleType name="NameSType"><restriction base="string"><minLength value="1"/><maxLength value="20"/></restriction></simpleType>
  <simpleType name="CodeSType"><restriction base="string"><pattern value="[a-z]{2}\\/[0-9]"/></restriction></simpleType>
</schema>
"""

MINI_WSDL = """<?xml version="1.0" encoding="UTF-8"?>
<wsdl:definitions xmlns:wsdl="http://schemas.xmlsoap.org/wsdl/" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
  xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:me="urn:w" xmlns:mes="urn:t" targetNamespace="urn:w">
  <wsdl:types><xsd:schema><xsd:import namespace="urn:t" schemaLocation="mini.xsd"/></xsd:schema></wsdl:types>
  <wsdl:message name="RootIn"><wsdl:part element="mes:Root" name="request"/></wsdl:message>
  <wsdl:message name="RootOut"><wsdl:part element="mes:Root" name="response"/></wsdl:message>
  <wsdl:portType name="P"><wsdl:operation name="doRoot"><wsdl:input message="me:RootIn"/><wsdl:output message="me:RootOut"/></wsdl:operation></wsdl:portType>
  <wsdl:binding name="B" type="me:P"><soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
    <wsdl:operation name="doRoot"><soap:operation soapAction="urn:w/DoRoot"/></wsdl:operation></wsdl:binding>
  <wsdl:service name="S"><wsdl:port binding="me:B" name="Port"><soap:address location="https://example.invalid/x"/></wsdl:port></wsdl:service>
</wsdl:definitions>
"""


class BundledRevisionTest(unittest.TestCase):
    def test_regenerated_catalog_is_byte_identical_to_committed(self) -> None:
        text = dumps(generate(REVISION / "official" / "eficg-fiscalization-service.xsd",
                              REVISION / "official" / "FiscalizationService.wsdl", "2026-11-13"))
        self.assertEqual((REVISION / "message-catalog.json").read_text(encoding="utf-8"), text)

    def test_counts_match_the_analysis(self) -> None:
        cat = json.loads((REVISION / "message-catalog.json").read_text(encoding="utf-8"))
        self.assertEqual(cat["meta"]["counts"]["simpleTypes"], 35)
        self.assertEqual(cat["meta"]["counts"]["complexTypes"], 32)
        self.assertEqual(len(cat["operations"]), 3)
        attrs = sum(1 for m in cat["messages"].values() for n in m if n["kind"] == "attribute")
        self.assertGreater(attrs, 100)

    def test_sha256sums_match_bundled_files(self) -> None:
        import hashlib
        sums = drift_check.read_sums(REVISION / "SHA256SUMS")
        for name, digest in sums.items():
            self.assertEqual(hashlib.sha256((REVISION / "official" / name).read_bytes()).hexdigest(), digest, name)


class SyntheticSchemaTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp())
        (self.tmp / "mini.xsd").write_text(MINI_XSD, encoding="utf-8")
        (self.tmp / "mini.wsdl").write_text(MINI_WSDL, encoding="utf-8")
        self.cat = generate(self.tmp / "mini.xsd", self.tmp / "mini.wsdl", "2000-01-01")

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp)

    def nodes(self) -> dict[str, dict]:
        return {n["xpath"]: n for n in self.cat["messages"]["Root"]}

    def test_operation_from_wsdl(self) -> None:
        self.assertEqual(self.cat["operations"], [{"name": "doRoot", "soapAction": "urn:w/DoRoot",
                                                   "requestElement": "Root", "responseElement": "Root"}])

    def test_documentation_is_trimmed(self) -> None:
        self.assertEqual(self.nodes()["/Root"]["doc"], "Root doc.")

    def test_fixed_attribute_and_cardinality(self) -> None:
        n = self.nodes()["/Root/@Id"]
        self.assertEqual((n["fixed"], n["cardinality"], n["required"]), ("Request", "1..1", True))

    def test_unique_constraint_and_sequence_occurs(self) -> None:
        n = self.nodes()
        self.assertEqual(n["/Root/List"]["unique"][0]["fields"], ["@K"])
        self.assertEqual(n["/Root/List/X"]["cardinality"], "1..5")
        self.assertEqual(n["/Root/List/X"]["compositorOfParent"], "sequence")

    def test_simple_content_produces_text_node_and_enum(self) -> None:
        n = self.nodes()
        self.assertEqual(n["/Root/List/X/text()"]["kind"], "text")
        self.assertEqual(n["/Root/List/X/@K"]["enum"], ["A", "B-C"])
        self.assertEqual(n["/Root/List/X/@N"]["maxLength"], 20)
        self.assertEqual(self.cat["simpleTypes"]["KindSType"]["enum"][0], {"value": "A", "doc": "First"})

    def test_pattern_kept_literally_including_escaped_slash(self) -> None:
        self.assertEqual(self.nodes()["/Root/Code"]["patterns"], ["[a-z]{2}\\/[0-9]"])
        self.assertEqual(self.nodes()["/Root/Code"]["content"], "simple")

    def test_ref_is_external(self) -> None:
        self.assertTrue(self.nodes()["/Root/ds:Signature"]["external"])


class DriftCheckTest(unittest.TestCase):
    def test_no_drift_against_bundled_files(self) -> None:
        rc = drift_check.main(["--revision", str(REVISION), "--local-dir", str(REVISION / "official")])
        self.assertEqual(rc, 0)

    def test_drift_is_reported(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        try:
            for f in drift_check.FILES:
                shutil.copy(REVISION / "official" / f, tmp / f)
            xsd = tmp / "eficg-fiscalization-service.xsd"
            xsd.write_text(xsd.read_text(encoding="utf-8").replace('name="Note"', 'name="Remark"'), encoding="utf-8")
            report = tmp / "report.md"
            rc = drift_check.main(["--revision", str(REVISION), "--local-dir", str(tmp), "--report", str(report)])
            self.assertEqual(rc, 3)
            text = report.read_text(encoding="utf-8")
            self.assertIn("added `/RegisterInvoiceRequest/Invoice/@Remark`", text)
            self.assertIn("removed `/RegisterInvoiceRequest/Invoice/@Note`", text)
        finally:
            shutil.rmtree(tmp)


if __name__ == "__main__":
    unittest.main()
