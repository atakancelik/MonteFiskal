"""Writes assets/brand/logo-wordmark.svg (and a dark-theme variant) with the name as outlines, so it renders the same without the font.

Development tool only; the SVG is committed. Needs: pip install fonttools uharfbuzz, and Inter Bold (SIL OFL 1.1).
Usage: python3 tools/brand/wordmark.py /path/to/Inter-Bold.otf
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

import uharfbuzz as hb
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

ROOT = Path(__file__).resolve().parents[2]
SIZE = 132            # font size in viewBox units; the tile is 256 high
GAP = 44              # space between tile and name
TRACKING = -2.5       # letter spacing in viewBox units
PAD = 4               # right padding


def outline(font_path: str, parts: list[tuple[str, str]], x0: float, baseline: float) -> tuple[str, float]:
    data = Path(font_path).read_bytes()
    face = hb.Face(data)
    hbfont = hb.Font(face)
    tt = TTFont(font_path)
    glyphs = tt.getGlyphSet()
    order = tt.getGlyphOrder()
    scale = SIZE / tt["head"].unitsPerEm
    x = x0
    out = []
    text = "".join(t for t, _ in parts)
    buf = hb.Buffer()
    buf.add_str(text)
    buf.guess_segment_properties()
    hb.shape(hbfont, buf, {"kern": True, "liga": True})
    colours = [c for t, c in parts for _ in t]
    groups: dict[str, list[str]] = {}
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        pen = SVGPathPen(glyphs)
        tpen = TransformPen(pen, (scale, 0, 0, -scale, x + pos.x_offset * scale, baseline - pos.y_offset * scale))
        glyphs[order[info.codepoint]].draw(tpen)
        groups.setdefault(colours[info.cluster], []).append(pen.getCommands())
        x += pos.x_advance * scale + TRACKING
    for colour, cmds in groups.items():
        d = " ".join(cmds)
        d = re.sub(r"(\d+\.\d{2})\d+", r"\1", d)
        out.append(f'  <path fill="{colour}" d="{d}"/>')
    return "\n".join(out), x - TRACKING


def main() -> int:
    font = sys.argv[1] if len(sys.argv) > 1 else "/usr/share/fonts/opentype/inter/Inter-Bold.otf"
    mark = (ROOT / "assets/brand/logo.svg").read_text(encoding="utf-8")
    inner = mark.split("</defs>", 1)[1].rsplit("</svg>", 1)[0]
    inner = re.sub(r"\s*<!--.*?-->", "", inner).strip("\n")
    defs = mark.split("<defs>", 1)[1].split("</defs>", 1)[0]
    # Cap height of Inter is about 0.727 em; centre the capitals on the tile.
    baseline = 128 + SIZE * 0.727 / 2
    for name, monte, fiskal in (("logo-wordmark.svg", "#1B1B1F", "#C8102E"), ("logo-wordmark-dark.svg", "#F0F0F3", "#FF5468")):
        write(name, defs, inner, *outline(font, [("Monte", monte), ("Fiskal", fiskal)], 256 + GAP, baseline))
    return 0


def write(name: str, defs: str, inner: str, paths: str, end: float) -> None:
    width = round(end + PAD)
    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} 256" role="img" aria-labelledby="t">
  <title id="t">MonteFiskal</title>
  <defs>{defs}</defs>
  <g>
{inner}
  </g>
{paths}
</svg>
"""
    (ROOT / "assets/brand" / name).write_text(svg, encoding="utf-8")
    print(f"assets/brand/{name}, viewBox width {width}")


if __name__ == "__main__":
    sys.exit(main())
