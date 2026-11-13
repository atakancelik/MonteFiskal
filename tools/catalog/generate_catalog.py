#!/usr/bin/env python3
"""Generate the machine-readable EFI message catalog from the official WSDL and XSD.

The catalog (``message-catalog.json``) is the source of truth for code generation and
validation in MonteFiskal (docs/analysis/architecture.md, section 5). This tool reads the
unmodified official files and writes the catalog deterministically, so that a schema
change on the server produces a reviewable diff.

Usage::

    python3 tools/catalog/generate_catalog.py \
        --xsd schemas/fs-v1/2026-11/official/eficg-fiscalization-service.xsd \
        --wsdl schemas/fs-v1/2026-11/official/FiscalizationService.wsdl \
        --generated-on 2026-11-13 \
        --out schemas/fs-v1/2026-11/message-catalog.json

Only the Python standard library is used.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any

XS = "http://www.w3.org/2001/XMLSchema"
WSDL = "http://schemas.xmlsoap.org/wsdl/"
SOAP = "http://schemas.xmlsoap.org/wsdl/soap/"
VC = "http://www.w3.org/2007/XMLSchema-versioning"

DEFAULT_COMMENT = "Machine-readable catalog of the EFI fiscalization service messages. See message-catalog.md."
DEFAULT_STATUS_NOTE = (
    "Generated mechanically from the official WSDL and XSD served by the EFI test environment; "
    "the XSD served by the production environment is byte-identical (see sources/README.md)."
)
DEFAULT_XSD_SOURCE = "https://efitest.tax.gov.me/fs-v1/eficg-fiscalization-service.xsd"
DEFAULT_WSDL_SOURCE = "https://efitest.tax.gov.me/fs-v1/FiscalizationService.wsdl"

BUILTINS = {
    "string", "decimal", "int", "integer", "double", "float", "boolean", "date", "dateTime",
    "time", "long", "short", "byte", "base64Binary", "hexBinary", "anyURI",
}


def q(ns: str, name: str) -> str:
    return "{%s}%s" % (ns, name)


def local(qname: str) -> str:
    """Strip a namespace prefix such as ``me:`` from a QName in an attribute value."""
    return qname.split(":", 1)[1] if ":" in qname else qname


def documentation(node: ET.Element) -> str | None:
    ann = node.find(q(XS, "annotation"))
    if ann is None:
        return None
    doc = ann.find(q(XS, "documentation"))
    if doc is None or doc.text is None:
        return None
    return doc.text.strip()


def occurs(value: str | None, default: int) -> int | str:
    if value is None:
        return default
    if value == "unbounded":
        return value
    return int(value)


INTEGER_FACETS = {"minLength", "maxLength", "length", "totalDigits", "fractionDigits"}


def facet_value(facet: str, value: str) -> int | str:
    """Length facets are integers in the catalog; value facets keep their lexical form."""
    return int(value) if facet in INTEGER_FACETS else value


class Schema:
    def __init__(self, root: ET.Element) -> None:
        self.root = root
        self.simple: dict[str, ET.Element] = {}
        self.complex: dict[str, ET.Element] = {}
        self.elements: list[ET.Element] = []
        for child in root:
            if child.tag == q(XS, "simpleType"):
                self.simple[child.get("name")] = child
            elif child.tag == q(XS, "complexType"):
                self.complex[child.get("name")] = child
            elif child.tag == q(XS, "element"):
                self.elements.append(child)

    # ---- simple types -------------------------------------------------------------
    def simple_type_entry(self, node: ET.Element) -> dict[str, Any]:
        entry: dict[str, Any] = {"doc": documentation(node)}
        restriction = node.find(q(XS, "restriction"))
        entry["base"] = local(restriction.get("base")) if restriction is not None else None
        if restriction is not None:
            enums = restriction.findall(q(XS, "enumeration"))
            patterns = restriction.findall(q(XS, "pattern"))
            if patterns:
                entry["patterns"] = [p.get("value") for p in patterns]
            if enums:
                entry["enum"] = [{"value": e.get("value"), "doc": documentation(e)} for e in enums]
            for facet in ("minLength", "maxLength", "minExclusive", "maxExclusive", "minInclusive",
                          "maxInclusive", "length", "totalDigits", "fractionDigits"):
                f = restriction.find(q(XS, facet))
                if f is not None:
                    entry[facet] = facet_value(facet, f.get("value"))
        return entry

    def facets_for(self, type_name: str) -> dict[str, Any]:
        """Facets of a named simple type in node order: builtinType, then constraints."""
        node = self.simple.get(type_name)
        if node is None:
            return {"builtinType": type_name}
        restriction = node.find(q(XS, "restriction"))
        base = local(restriction.get("base"))
        out: dict[str, Any] = {"builtinType": base}
        return out

    def constraints_for(self, type_name: str) -> dict[str, Any]:
        node = self.simple.get(type_name)
        out: dict[str, Any] = {}
        if node is None:
            return out
        restriction = node.find(q(XS, "restriction"))
        patterns = restriction.findall(q(XS, "pattern"))
        enums = restriction.findall(q(XS, "enumeration"))
        if patterns:
            out["patterns"] = [p.get("value") for p in patterns]
        if enums:
            out["enum"] = [e.get("value") for e in enums]
            out["enumType"] = type_name
        for facet in ("minLength", "maxLength", "minExclusive"):
            f = restriction.find(q(XS, facet))
            if f is not None:
                out[facet] = facet_value(facet, f.get("value"))
        return out

    # ---- complex types ------------------------------------------------------------
    @staticmethod
    def compositor_of(ct: ET.Element) -> ET.Element | None:
        for name in ("all", "sequence", "choice"):
            c = ct.find(q(XS, name))
            if c is not None:
                return c
        return None

    @staticmethod
    def attributes_of(ct: ET.Element) -> list[ET.Element]:
        sc = ct.find(q(XS, "simpleContent"))
        if sc is not None:
            ext = sc.find(q(XS, "extension"))
            return ext.findall(q(XS, "attribute"))
        return ct.findall(q(XS, "attribute"))

    def complex_type_entry(self, ct: ET.Element) -> dict[str, Any]:
        comp = self.compositor_of(ct)
        entry: dict[str, Any] = {
            "doc": documentation(ct),
            "compositor": comp.tag.split("}")[1] if comp is not None else None,
            "elements": [],
            "attributes": [],
        }
        if comp is not None:
            for el in comp.findall(q(XS, "element")):
                e: dict[str, Any] = {
                    "name": el.get("name") or el.get("ref"),
                    "isRef": el.get("ref") is not None,
                    "type": el.get("type") if el.get("ref") is None else el.get("ref"),
                    "minOccurs": occurs(el.get("minOccurs"), 1),
                    "maxOccurs": occurs(el.get("maxOccurs"), 1),
                    "doc": documentation(el),
                }
                uniques = self.uniques_of(el)
                if uniques:
                    e["unique"] = uniques
                entry["elements"].append(e)
        for at in self.attributes_of(ct):
            entry["attributes"].append({
                "name": at.get("name"),
                "type": at.get("type"),
                "use": at.get("use") or "optional",
                "doc": documentation(at),
            })
        if comp is not None:
            entry["compositorMinOccurs"] = occurs(comp.get("minOccurs"), 1)
            entry["compositorMaxOccurs"] = comp.get("maxOccurs") or "1"
        sc = ct.find(q(XS, "simpleContent"))
        if sc is not None:
            entry["simpleContentBase"] = local(sc.find(q(XS, "extension")).get("base"))
        return entry

    @staticmethod
    def uniques_of(el: ET.Element) -> list[dict[str, Any]]:
        out = []
        for u in el.findall(q(XS, "unique")):
            out.append({
                "name": u.get("name"),
                "selector": u.find(q(XS, "selector")).get("xpath"),
                "fields": [f.get("xpath") for f in u.findall(q(XS, "field"))],
                "doc": documentation(u),
            })
        return out

    # ---- message trees ------------------------------------------------------------
    def walk_complex(self, ct: ET.Element, type_label: str, xpath: str, nodes: list[dict[str, Any]]) -> None:
        for at in self.attributes_of(ct):
            nodes.append(self.attribute_node(at, type_label, xpath))
        sc = ct.find(q(XS, "simpleContent"))
        if sc is not None:
            base = local(sc.find(q(XS, "extension")).get("base"))
            nodes.append({
                "xpath": xpath + "/text()", "kind": "text", "name": "text()", "parentType": type_label,
                "type": base, "builtinType": base, "required": True, "cardinality": "1..1", "doc": None,
            })
        comp = self.compositor_of(ct)
        if comp is None:
            return
        comp_name = comp.tag.split("}")[1]
        for el in comp.findall(q(XS, "element")):
            self.element_node(el, type_label, xpath, comp_name, nodes)

    def attribute_node(self, at: ET.Element, parent_type: str, xpath: str) -> dict[str, Any]:
        type_name = local(at.get("type"))
        required = at.get("use") == "required"
        node: dict[str, Any] = {
            "xpath": f"{xpath}/@{at.get('name')}", "kind": "attribute", "name": at.get("name"),
            "parentType": parent_type, "type": type_name,
        }
        node["builtinType"] = self.facets_for(type_name)["builtinType"]
        node["required"] = required
        node["cardinality"] = "1..1" if required else "0..1"
        if at.get("fixed") is not None:
            node["fixed"] = at.get("fixed")
        node.update(self.constraints_for(type_name))
        node["doc"] = documentation(at)
        return node

    def element_node(self, el: ET.Element, parent_type: str, xpath: str, comp_name: str,
                     nodes: list[dict[str, Any]]) -> None:
        min_o = occurs(el.get("minOccurs"), 1)
        max_o = occurs(el.get("maxOccurs"), 1)
        card = f"{min_o}..{max_o}"
        if el.get("ref") is not None:
            ref = el.get("ref")
            nodes.append({
                "xpath": f"{xpath}/{ref}", "kind": "element", "name": ref, "parentType": parent_type,
                "type": "(ref)", "required": min_o != 0, "cardinality": card,
                "compositorOfParent": comp_name, "doc": documentation(el), "external": True,
            })
            return
        name = el.get("name")
        type_name = local(el.get("type"))
        node: dict[str, Any] = {
            "xpath": f"{xpath}/{name}", "kind": "element", "name": name, "parentType": parent_type,
            "type": type_name, "required": min_o != 0, "cardinality": card,
            "compositorOfParent": comp_name, "doc": documentation(el),
        }
        uniques = self.uniques_of(el)
        if uniques:
            node["unique"] = uniques
        if type_name in self.complex:
            node["content"] = "complex"
            nodes.append(node)
            self.walk_complex(self.complex[type_name], type_name, node["xpath"], nodes)
        else:
            node["content"] = "simple"
            node["builtinType"] = self.facets_for(type_name)["builtinType"]
            c = self.constraints_for(type_name)
            node.update(c)
            nodes.append(node)

    def message(self, el: ET.Element) -> list[dict[str, Any]]:
        name = el.get("name")
        nodes: list[dict[str, Any]] = [{
            "xpath": "/" + name, "kind": "element", "name": name, "parentType": None,
            "type": "(anonymous)", "required": True, "cardinality": "1..1", "content": "complex",
            "doc": documentation(el),
        }]
        ct = el.find(q(XS, "complexType"))
        self.walk_complex(ct, "(anonymous)", "/" + name, nodes)
        return nodes


def parse_wsdl(path: Path) -> tuple[ET.Element, list[dict[str, Any]], dict[str, Any]]:
    root = ET.parse(path).getroot()
    messages = {m.get("name"): local(m.find(q(WSDL, "part")).get("element")) for m in root.findall(q(WSDL, "message"))}
    port = root.find(q(WSDL, "portType"))
    binding = root.find(q(WSDL, "binding"))
    actions = {}
    for op in binding.findall(q(WSDL, "operation")):
        actions[op.get("name")] = op.find(q(SOAP, "operation")).get("soapAction")
    ops = []
    for op in port.findall(q(WSDL, "operation")):
        ops.append({
            "name": op.get("name"),
            "soapAction": actions[op.get("name")],
            "requestElement": messages[local(op.find(q(WSDL, "input")).get("message"))],
            "responseElement": messages[local(op.find(q(WSDL, "output")).get("message"))],
        })
    sb = binding.find(q(SOAP, "binding"))
    address = root.find(q(WSDL, "service")).find(q(WSDL, "port")).find(q(SOAP, "address")).get("location")
    imp = root.find(q(WSDL, "types")).find(q(XS, "schema")).find(q(XS, "import"))
    info = {
        "targetNamespace": root.get("targetNamespace"),
        "style": sb.get("style"),
        "transport": sb.get("transport"),
        "address": address,
        "schemaLocation": imp.get("schemaLocation"),
    }
    return root, ops, info


def generate(xsd: Path, wsdl: Path, generated_on: str, xsd_source: str = DEFAULT_XSD_SOURCE,
             wsdl_source: str = DEFAULT_WSDL_SOURCE) -> dict[str, Any]:
    xsd_bytes = xsd.read_bytes()
    wsdl_bytes = wsdl.read_bytes()
    root = ET.fromstring(xsd_bytes)
    schema = Schema(root)
    _, ops, winfo = parse_wsdl(wsdl)

    messages = {el.get("name"): schema.message(el) for el in schema.elements}
    simple = {name: schema.simple_type_entry(node) for name, node in schema.simple.items()}
    complex_ = {name: schema.complex_type_entry(node) for name, node in schema.complex.items()}

    return {
        "$comment": DEFAULT_COMMENT,
        "meta": {
            "status": "VERIFIED",
            "statusNote": DEFAULT_STATUS_NOTE,
            "generatedOn": generated_on,
            "wsdlSource": wsdl_source,
            "xsdSource": xsd_source,
            "xsdSha256": hashlib.sha256(xsd_bytes).hexdigest(),
            "wsdlSha256": hashlib.sha256(wsdl_bytes).hexdigest(),
            "targetNamespace": root.get("targetNamespace"),
            "elementFormDefault": root.get("elementFormDefault"),
            "xsdMinVersion": root.get(q(VC, "minVersion")),
            "wsdlTargetNamespace": winfo["targetNamespace"],
            "soapBindingStyle": winfo["style"],
            "soapTransport": winfo["transport"],
            "soapAddressInWsdl": winfo["address"],
            "schemaLocationInWsdl": winfo["schemaLocation"],
            "counts": {
                "simpleTypes": len(simple),
                "complexTypes": len(complex_),
                "globalElements": len(schema.elements),
                "nodes": {k: len(v) for k, v in messages.items()},
            },
        },
        "operations": ops,
        "messages": messages,
        "simpleTypes": simple,
        "complexTypes": complex_,
    }


def dumps(catalog: dict[str, Any]) -> str:
    return json.dumps(catalog, indent=2, ensure_ascii=False) + "\n"


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--xsd", required=True, type=Path)
    p.add_argument("--wsdl", required=True, type=Path)
    p.add_argument("--generated-on", required=True, help="date written to meta.generatedOn (YYYY-MM-DD)")
    p.add_argument("--xsd-source", default=DEFAULT_XSD_SOURCE)
    p.add_argument("--wsdl-source", default=DEFAULT_WSDL_SOURCE)
    p.add_argument("--out", type=Path, help="output file; standard output when omitted")
    p.add_argument("--check", action="store_true", help="fail when --out differs from the generated catalog")
    args = p.parse_args(argv)

    text = dumps(generate(args.xsd, args.wsdl, args.generated_on, args.xsd_source, args.wsdl_source))
    if args.check:
        if args.out is None:
            p.error("--check needs --out")
        current = args.out.read_text(encoding="utf-8")
        if current != text:
            print(f"{args.out} is out of date; regenerate it", file=sys.stderr)
            return 1
        return 0
    if args.out:
        args.out.write_text(text, encoding="utf-8", newline="\n")
    else:
        sys.stdout.write(text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
