"""Compiles and runs every C# and PHP code block of the assistant kit and the package documentation.

A C# block is a top-level program: it must compile against the MonteFiskal projects. A PHP block must pass `php -l`.
A block that uses the fake service (`FakeEfiEndpoint`) is also executed and must exit with code 0. Blocks that need a
real certificate or network are compiled or linted only. A renamed API therefore breaks this job, not the reader's
first try.

Usage: python3 -I tools/ai-kit/run_blocks.py --work <temporary folder> [--only csharp|php]
Environment: MF_PHP overrides the PHP command (default "php"), MF_DOTNET the dotnet command (default "dotnet").
"""
from __future__ import annotations

import argparse
import os
import re
import shlex
import shutil
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from common import ROOT  # noqa: E402

DOC_GLOBS = [
    "README.md",
    "docs/ai/**/*.md",
    "docs/api/*.md",
    "docs/setup-guide.md",
    "src/dotnet/README.md",
]
DOTNET_PROJECTS = [
    "MonteFiskal",
    "MonteFiskal.Extensions.DependencyInjection",
    "MonteFiskal.Storage.EntityFrameworkCore",
    "MonteFiskal.Storage.FileSystem",
    "MonteFiskal.Rendering",
    "MonteFiskal.Reports",
    "MonteFiskal.Testing",
]
FENCE = re.compile(r"^```([A-Za-z#+-]*)[^\n]*\n(.*?)^```\s*$", re.M | re.S)
LANGS = {"csharp": "csharp", "cs": "csharp", "c#": "csharp", "php": "php"}


@dataclass
class Block:
    path: Path
    line: int
    lang: str
    code: str

    @property
    def label(self) -> str:
        return f"{self.path.relative_to(ROOT)}:{self.line}"

    @property
    def runs(self) -> bool:
        return "FakeEfiEndpoint" in self.code


def blocks() -> list[Block]:
    seen: set[Path] = set()
    out: list[Block] = []
    for pattern in DOC_GLOBS:
        for path in sorted(ROOT.glob(pattern)):
            if path in seen or not path.is_file():
                continue
            seen.add(path)
            text = path.read_text(encoding="utf-8")
            for m in FENCE.finditer(text):
                lang = LANGS.get(m.group(1).lower())
                if lang:
                    out.append(Block(path, text.count("\n", 0, m.start()) + 1, lang, m.group(2)))
    return out


def run(cmd: list[str], cwd: Path | None = None, timeout: int = 600) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout)


def csharp(items: list[Block], work: Path) -> list[str]:
    dotnet = shlex.split(os.environ.get("MF_DOTNET", "dotnet"))
    project = work / "dotnet"
    project.mkdir(parents=True, exist_ok=True)
    refs = "\n".join(
        f'    <ProjectReference Include="{ROOT / "src/dotnet" / p / (p + ".csproj")}" />' for p in DOTNET_PROJECTS
    )
    (project / "Block.csproj").write_text(
        f"""<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <OutputType>Exe</OutputType>
    <TargetFramework>net10.0</TargetFramework>
    <ImplicitUsings>enable</ImplicitUsings>
    <Nullable>enable</Nullable>
    <AssemblyName>Block</AssemblyName>
    <TreatWarningsAsErrors>false</TreatWarningsAsErrors>
  </PropertyGroup>
  <ItemGroup>
{refs}
  </ItemGroup>
</Project>
""",
        encoding="utf-8",
    )
    failures: list[str] = []
    restored = False
    for b in items:
        (project / "Program.cs").write_text(b.code, encoding="utf-8")
        args = [*dotnet, "build", str(project), "-c", "Release", "--nologo", "-v", "q", "-clp:NoSummary;ErrorsOnly"]
        if restored:
            args.append("--no-restore")
        r = run(args)
        restored = restored or r.returncode == 0
        if r.returncode != 0:
            failures.append(f"{b.label}: does not compile\n{(r.stdout + r.stderr).strip()[-3000:]}")
            continue
        if not b.runs:
            print(f"compiled  {b.label}")
            continue
        r = run([*dotnet, str(project / "bin/Release/net10.0/Block.dll")], timeout=300)
        if r.returncode != 0:
            failures.append(f"{b.label}: exit {r.returncode}\n{(r.stdout + r.stderr).strip()[-3000:]}")
        else:
            print(f"ran       {b.label}: {r.stdout.strip().splitlines()[-1] if r.stdout.strip() else ''}")
    return failures


def php(items: list[Block], work: Path) -> list[str]:
    cmd = shlex.split(os.environ.get("MF_PHP", "php"))
    folder = work / "php"
    folder.mkdir(parents=True, exist_ok=True)
    autoload = folder / "autoload.php"
    autoload.write_text(
        "<?php\nspl_autoload_register(static function (string $c): void {\n"
        "    if (str_starts_with($c, 'MonteFiskal\\\\')) {\n"
        f"        $f = {str(ROOT / 'src/php/montefiskal-core/src')!r} . '/' . str_replace('\\\\', '/', substr($c, 12)) . '.php';\n"
        "        if (is_file($f)) { require $f; }\n    }\n});\n",
        encoding="utf-8",
    )
    failures: list[str] = []
    for i, b in enumerate(items):
        code = b.code if b.code.lstrip().startswith("<?php") else "<?php\n" + b.code
        file = folder / f"block{i}.php"
        file.write_text(code, encoding="utf-8")
        r = run([*cmd, "-l", str(file)])
        if r.returncode != 0:
            failures.append(f"{b.label}: syntax\n{(r.stdout + r.stderr).strip()}")
            continue
        if not b.runs:
            print(f"linted    {b.label}")
            continue
        r = run([*cmd, "-d", f"auto_prepend_file={autoload}", str(file)], timeout=300)
        if r.returncode != 0:
            failures.append(f"{b.label}: exit {r.returncode}\n{(r.stdout + r.stderr).strip()[-3000:]}")
        else:
            print(f"ran       {b.label}: {r.stdout.strip().splitlines()[-1] if r.stdout.strip() else ''}")
    return failures


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--work", required=True, type=Path)
    ap.add_argument("--only", choices=["csharp", "php"])
    a = ap.parse_args()
    work = a.work.resolve()
    if work.is_relative_to(ROOT):
        print("--work must be outside the repository (the build would inherit its Directory.*.props)", file=sys.stderr)
        return 2
    shutil.rmtree(work, ignore_errors=True)
    found = blocks()
    failures: list[str] = []
    for lang, fn in (("csharp", csharp), ("php", php)):
        if a.only in (None, lang):
            items = [b for b in found if b.lang == lang]
            failures += fn(items, work)
    count = sum(1 for b in found if a.only in (None, b.lang))
    print(f"{count} blocks, {len(failures)} failed")
    for f in failures:
        print("\nFAIL " + f, file=sys.stderr)
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
