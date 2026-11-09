#!/usr/bin/env python3
"""Build the uploadable PHP file deterministically; --check detects stale builds."""
from pathlib import Path
import argparse
import sys

root = Path(__file__).resolve().parents[1]
parts = ["bootstrap.php", "loader-core.php", "security.php", "broker.php", "application.php", "template.php"]
output = []
for name in parts:
    data = (root / "src" / name).read_text(encoding="utf-8")
    if not data.startswith("<?php\n"):
        raise SystemExit(f"Expected PHP opening tag in {name}")
    output.append(data if not output else "\n/* SOURCE: " + name + " */\n" + data[6:])
rendered = "".join(output)
parser = argparse.ArgumentParser()
parser.add_argument("--check", action="store_true")
args = parser.parse_args()
destination = root / "loader.php"
if args.check:
    if not destination.exists() or destination.read_text(encoding="utf-8") != rendered:
        raise SystemExit("loader.php does not match src/. Run python3 scripts/build.py")
    print("Single-file build matches source modules.")
else:
    destination.write_text(rendered, encoding="utf-8")
    print("Built loader.php")
