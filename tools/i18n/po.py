#!/usr/bin/env python3
"""Translation helper for the plugin, standard library only.

  python3 -I tools/i18n/po.py compile <file.po>          writes <file.mo> next to it
  python3 -I tools/i18n/po.py check <file.pot> <file.po> fails when a msgid of the template is missing or untranslated
"""
from __future__ import annotations

import ast
import re
import struct
import sys
from pathlib import Path

ENTRY = re.compile(r'(?:^msgctxt (".*")\n)?^msgid (".*"(?:\n".*")*)\n(?:^msgid_plural (".*"(?:\n".*")*)\n)?((?:^msgstr(?:\[\d\])? ".*"(?:\n".*")*\n?)+)?', re.M)


def unq(block: str | None) -> str:
    if not block:
        return ''
    return ''.join(ast.literal_eval(line) for line in block.splitlines())


def parse(path: Path) -> list[dict]:
    text = path.read_text(encoding='utf-8')
    out = []
    for m in ENTRY.finditer(text):
        strs = []
        for line in re.finditer(r'^msgstr(?:\[(\d)\])? (".*"(?:\n".*")*)', m.group(4) or '', re.M):
            strs.append(unq(line.group(2)))
        out.append({'ctx': unq(m.group(1)) if m.group(1) else None, 'id': unq(m.group(2)), 'plural': unq(m.group(3)) if m.group(3) else None, 'str': strs})
    return out


def key(e: dict) -> str:
    k = e['id'] if e['plural'] is None else e['id'] + '\0' + e['plural']
    return (e['ctx'] + '\x04' + k) if e['ctx'] else k


def compile_mo(po: Path) -> Path:
    entries = parse(po)
    pairs = {}
    for e in entries:
        value = '\0'.join(e['str'])
        if e['id'] == '' or any(s for s in e['str']):
            pairs[key(e)] = value
    ids = sorted(pairs)
    kb = [k.encode('utf-8') for k in ids]
    vb = [pairs[k].encode('utf-8') for k in ids]
    n = len(ids)
    start = 7 * 4 + 16 * n
    koffs, voffs, kdata, vdata = [], [], b'', b''
    for k in kb:
        koffs.append((len(k), start + len(kdata)))
        kdata += k + b'\0'
    vstart = start + len(kdata)
    for v in vb:
        voffs.append((len(v), vstart + len(vdata)))
        vdata += v + b'\0'
    header = struct.pack('<7I', 0x950412DE, 0, n, 7 * 4, 7 * 4 + 8 * n, 0, 0)
    table = b''.join(struct.pack('<2I', *o) for o in koffs) + b''.join(struct.pack('<2I', *o) for o in voffs)
    mo = po.with_suffix('.mo')
    mo.write_bytes(header + table + kdata + vdata)
    return mo


def check(pot: Path, po: Path) -> int:
    have = {key(e): e for e in parse(po)}
    missing = [e['id'] for e in parse(pot) if e['id'] and not any(have.get(key(e), {}).get('str', []))]
    for m in missing:
        print(f'untranslated: {m}')
    print(f'{po.name}: {len(missing)} untranslated')
    return 1 if missing else 0


def main() -> int:
    if len(sys.argv) >= 3 and sys.argv[1] == 'compile':
        print(compile_mo(Path(sys.argv[2])))
        return 0
    if len(sys.argv) == 4 and sys.argv[1] == 'check':
        return check(Path(sys.argv[2]), Path(sys.argv[3]))
    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main())
