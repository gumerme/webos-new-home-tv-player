#!/usr/bin/env python3
"""
Free My TV for webOS - Homebrew Channel manifest generator.

Writes dist/<app id>.manifest.json next to the ipk: the file the webosbrew.org
apps repository points at (via `manifestUrl` in homebrew/org.webos.freemytv.yml).
Run after scripts/build.py; the release workflow attaches it to each GitHub release.

The field names follow the manifests other Homebrew Channel apps publish; the
apps-repo README does not define the schema, so verify against a current
manifest there before submitting.
"""

import hashlib
import json
import os
import sys

REPO_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GITHUB = "https://github.com/danielthejoker18/FreeMyTvWebOS"


def main():
    with open(os.path.join(REPO_DIR, "app", "appinfo.json"), encoding="utf-8") as f:
        info = json.load(f)
    ipk_name = f"{info['id']}_{info['version']}_all.ipk"
    ipk_path = os.path.join(REPO_DIR, "dist", ipk_name)
    if not os.path.exists(ipk_path):
        print(f"[!] {ipk_name} not found - run python scripts/build.py first")
        return 1
    with open(ipk_path, "rb") as f:
        sha256 = hashlib.sha256(f.read()).hexdigest()

    manifest = {
        "id": info["id"],
        "version": info["version"],
        "type": info["type"],
        "title": info["title"],
        "appDescription": "A fast, ad-free 60fps home screen for LG webOS TVs.",
        "iconUri": f"{GITHUB}/raw/main/docs/assets/icon-512.png",
        "sourceUrl": GITHUB,
        "rootRequired": False,
        "ipkUrl": f"{GITHUB}/releases/download/v{info['version']}/{ipk_name}",
        "ipkHash": {"sha256": sha256},
    }
    out = os.path.join(REPO_DIR, "dist", f"{info['id']}.manifest.json")
    with open(out, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
        f.write("\n")
    print(f"[+] wrote {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
