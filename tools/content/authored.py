"""Rebuild content/redux/manifest.json after editing hand-authored Redux content.

The version uses the same algorithm as ContentCatalog::verifyIntegrity(): SHA-256 over
the raw bytes of every kind file, in kind-name order.
"""

import argparse
import hashlib
import json
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--content", default="content/redux", help="authored content directory")
    args = parser.parse_args()
    root = Path(args.content)
    kinds = sorted(path.stem for path in root.glob("*.json") if path.stem != "manifest")
    digest = hashlib.sha256()
    counts = {}
    for kind in kinds:
        raw = (root / f"{kind}.json").read_bytes()
        data = json.loads(raw)
        for key, row in data.items():
            if row.get("id") != key or "data" not in row or row.get("source", {}).get("authored") is not True:
                raise SystemExit(f"{kind}/{key}: records need matching id, data and source.authored = true")
        digest.update(raw)
        counts[kind] = len(data)
    manifest = {"schema_version": 1, "content_version": digest.hexdigest(), "counts": counts}
    (root / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False))


if __name__ == "__main__":
    main()
