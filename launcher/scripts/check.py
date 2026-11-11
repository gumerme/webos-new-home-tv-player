#!/usr/bin/env python3
"""
Free My TV for webOS - repository checks (run by CI, runnable locally).

  1. scripts compile
  2. docs/ mirrors app/ (see sync_docs.py)
  3. the committed dist/ ipk matches app/appinfo.json's version
  4. APP_VERSION in app/index.html equals appinfo.json's version
  5. the ipk has no epoch-zero tar timestamps (webOS 5.x "-5: ipk verified failed")

`--extract-js PATH` writes app/index.html's inline JS to PATH so CI can run an
ES5 syntax check on it (webOS 3.x ships Chromium 38).
"""

import glob
import io
import json
import os
import re
import sys
import tarfile

REPO_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(REPO_DIR, "scripts"))
import sync_docs  # noqa: E402


def check_ipk(path):
    errors = []
    with open(path, "rb") as f:
        raw = f.read()
    if raw[:8] != b"!<arch>\n":
        return [f"{os.path.basename(path)}: not an ar archive"]
    offset = 8
    while offset + 60 <= len(raw):
        header = raw[offset:offset + 60]
        name = header[0:16].decode("ascii").strip()
        size = int(header[48:58].decode("ascii").strip())
        content = raw[offset + 60:offset + 60 + size]
        offset += 60 + size + (size % 2)
        if name in ("control.tar.gz", "data.tar.gz"):
            with tarfile.open(fileobj=io.BytesIO(content), mode="r:gz") as tar:
                for member in tar.getmembers():
                    if member.mtime <= 0:
                        errors.append(f"{os.path.basename(path)}: {name}:{member.name} has epoch-zero mtime")
    return errors


def extract_inline_js(out_path):
    with open(os.path.join(REPO_DIR, "app", "index.html"), encoding="utf-8") as f:
        html = f.read()
    scripts = re.findall(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", html, re.S)
    with open(out_path, "w", encoding="utf-8") as f:
        f.write("\n;\n".join(scripts))
    print(f"[*] wrote {len(scripts)} inline script(s) to {out_path}")


def main():
    if "--extract-js" in sys.argv:
        extract_inline_js(sys.argv[sys.argv.index("--extract-js") + 1])
        return 0

    errors = []
    for script in glob.glob(os.path.join(REPO_DIR, "scripts", "*.py")):
        with open(script, encoding="utf-8") as f:
            try:
                compile(f.read(), script, "exec")
            except SyntaxError as e:
                errors.append(f"{os.path.basename(script)}: {e}")

    if sync_docs.main(check=True):
        errors.append("docs/ is out of sync with app/")

    with open(os.path.join(REPO_DIR, "app", "appinfo.json"), encoding="utf-8") as f:
        info = json.load(f)
    with open(os.path.join(REPO_DIR, "app", "index.html"), encoding="utf-8") as f:
        m = re.search(r"var APP_VERSION = '([^']+)'", f.read())
    if not m or m.group(1) != info["version"]:
        errors.append(f"APP_VERSION in app/index.html ({m.group(1) if m else 'missing'}) != appinfo.json version {info['version']}")
    ipk = os.path.join(REPO_DIR, "dist", f"{info['id']}_{info['version']}_all.ipk")
    if not os.path.exists(ipk):
        errors.append(f"missing {os.path.relpath(ipk, REPO_DIR)} for appinfo version {info['version']} "
                      "(run python scripts/build.py)")
    else:
        errors += check_ipk(ipk)
    stale = [p for p in glob.glob(os.path.join(REPO_DIR, "dist", "*.ipk")) if p != ipk]
    if stale:
        errors.append("stale ipk(s) in dist/: " + ", ".join(os.path.basename(p) for p in stale))

    for e in errors:
        print(f"[!] {e}")
    print("[+] all checks passed" if not errors else f"[!] {len(errors)} problem(s)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
