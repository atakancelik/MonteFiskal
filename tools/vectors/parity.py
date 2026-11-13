"""vectors-parity: both languages must run every vector ID (architecture section 7).

Usage: python3 -I tools/vectors/parity.py dotnet.log php.log
Each log has one vector ID per line, written by the test suites when MONTEFISKAL_VECTOR_LOG is set.
"""
import json
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]


def ids(path: str) -> set[str]:
    return {line.strip() for line in pathlib.Path(path).read_text(encoding="utf-8").splitlines() if line.strip()}


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    every = {json.loads(p.read_text(encoding="utf-8"))["id"] for p in (ROOT / "test-vectors").glob("*/*.json") if p.parent.name != "schema"}
    dotnet, php = ids(sys.argv[1]), ids(sys.argv[2])
    problems = []
    for name, got in (("dotnet", dotnet), ("php", php)):
        missing = sorted(every - got)
        if missing:
            problems.append(f"{name} did not run: {', '.join(missing)}")
    if dotnet != php:
        problems.append("the sets differ: only .NET " + ", ".join(sorted(dotnet - php)) + "; only PHP " + ", ".join(sorted(php - dotnet)))
    if problems:
        print("vectors-parity: FAIL\n" + "\n".join(problems))
        return 1
    print(f"vectors-parity: both languages ran the same {len(every)} vectors")
    return 0


if __name__ == "__main__":
    sys.exit(main())
