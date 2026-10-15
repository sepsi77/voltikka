#!/usr/bin/env python3
"""Generate one image with Codex CLI (default) or OpenRouter (fallback), and save it to a folder.

Usage:
  image_gen.py "<description>" <out_dir> [--name NAME] [--ref IMG ...] [--transparent]
               [--aspect 4:5] [--backend codex|openrouter] [--model MODEL]

codex:      the built-in image_gen tool of `codex exec` (gpt-image-2). Uses the ChatGPT plan login,
            no API cost. Codex saves to ~/.codex/generated_images/<thread_id>/; this script copies the
            image from there. Codex runs in a read-only sandbox, so it cannot write files itself.
openrouter: POST https://openrouter.ai/api/v1/images (default model openai/gpt-image-2.5-sunburst).
            Paid. Reads OPENROUTER_API_KEY from the environment, else from laravel/.env of the repo.

Prints the saved path (and the cost for OpenRouter). Never overwrites a file. Standard library only.
"""

import argparse
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_OPENROUTER_MODEL = "openai/gpt-image-2.5-sunburst"
TRANSPARENT_TEXT = "Output a PNG with a genuinely transparent background (real alpha channel): only the subject, no backdrop, no floor, no cast shadow, no scenery."
EXT = {"image/png": ".png", "image/jpeg": ".jpg", "image/webp": ".webp"}
MIME = {".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".webp": "image/webp"}


def fail(msg: str) -> None:
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(1)


def has_alpha(path: Path) -> bool:
    """True when a PNG has an alpha channel (IHDR colour type 4 or 6)."""
    head = path.read_bytes()[:26]
    return head[:8] == b"\x89PNG\r\n\x1a\n" and head[25] in (4, 6)


def codex(description: str, refs: list[Path], transparent: bool, aspect: str | None) -> Path:
    if not shutil.which("codex"):
        fail("codex CLI not found; use --backend openrouter")
    extra = ""
    if aspect:
        extra += f"\nAspect ratio: {aspect}."
    if transparent:
        extra += "\n" + TRANSPARENT_TEXT
    prompt = (
        "Use your built-in image_gen tool to generate exactly one image. Do not write, copy or move any files, "
        "and do not run shell commands. Attached images (if any) are references only.\n\n"
        f"Image description:\n{description}{extra}"
    )
    with tempfile.TemporaryDirectory() as work:
        cmd = ["codex", "exec", "--skip-git-repo-check", "--ephemeral", "--json", "-s", "read-only", "-C", work]
        for ref in refs:
            cmd += ["--image", str(ref)]
        cmd += ["--", prompt]  # --image takes many values; "--" keeps the prompt out of it
        # stdin is closed: codex exec appends piped stdin to the prompt
        res = subprocess.run(cmd, stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=900)
    events = [json.loads(line) for line in res.stdout.splitlines() if line.startswith("{")]
    thread_id = next((e.get("thread_id") for e in events if e.get("type") == "thread.started"), None)
    src = Path(os.environ.get("CODEX_HOME", Path.home() / ".codex")) / "generated_images" / str(thread_id)
    images = sorted(src.glob("*")) if thread_id and src.is_dir() else []
    if res.returncode != 0 or not images:
        last = [e["item"].get("text") or e["item"].get("message") for e in events if e.get("item", {}).get("type") in ("agent_message", "error")]
        fail(f"codex made no image (exit {res.returncode}). Last message: {last[-1] if last else res.stderr[-800:]}")
    return images[-1]


def openrouter_key() -> str:
    if key := os.environ.get("OPENROUTER_API_KEY"):
        return key
    env = Path(__file__).resolve().parents[5] / "laravel" / ".env"  # design/.claude/skills/image-gen/scripts -> repo
    if env.is_file():
        for line in env.read_text().splitlines():
            if line.startswith("OPENROUTER_API_KEY=") and line.split("=", 1)[1].strip():
                return line.split("=", 1)[1].strip().strip("\"'")
    fail("OPENROUTER_API_KEY is not set and laravel/.env has none")
    raise AssertionError


def openrouter(description: str, refs: list[Path], transparent: bool, aspect: str | None, model: str) -> tuple[bytes, str, object]:
    body: dict = {"model": model, "prompt": description}
    if refs:
        body["input_references"] = [
            {"type": "image_url", "image_url": {"url": f"data:{MIME.get(r.suffix.lower(), 'image/png')};base64," + base64.b64encode(r.read_bytes()).decode()}}
            for r in refs
        ]
    if transparent:
        body |= {"background": "transparent", "output_format": "png"}
    if aspect:
        body["aspect_ratio"] = aspect
    req = urllib.request.Request(
        "https://openrouter.ai/api/v1/images",
        data=json.dumps(body).encode(),
        headers={"Authorization": f"Bearer {openrouter_key()}", "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=600) as resp:
            data = json.load(resp)
    except urllib.error.HTTPError as e:
        fail(f"OpenRouter HTTP {e.code}: {e.read().decode()[:600]}")
    item = data["data"][0]
    return base64.b64decode(item["b64_json"]), EXT.get(item.get("media_type", ""), ".png"), (data.get("usage") or {}).get("cost")


def main() -> None:
    p = argparse.ArgumentParser(description="Generate one image (Codex by default, OpenRouter as fallback).")
    p.add_argument("description")
    p.add_argument("out_dir", type=Path)
    p.add_argument("--name", default=time.strftime("image-%Y%m%d-%H%M%S"), help="file name without extension")
    p.add_argument("--ref", type=Path, action="append", default=[], help="reference image (repeatable)")
    p.add_argument("--transparent", action="store_true", help="ask for a transparent background")
    p.add_argument("--aspect", help="aspect ratio such as 1:1, 4:5, 16:9")
    p.add_argument("--backend", choices=["codex", "openrouter"], default="codex")
    p.add_argument("--model", default=DEFAULT_OPENROUTER_MODEL, help="OpenRouter model (openrouter backend only)")
    a = p.parse_args()

    for ref in a.ref:
        if not ref.is_file():
            fail(f"reference image not found: {ref}")
    a.out_dir.mkdir(parents=True, exist_ok=True)
    if existing := list(a.out_dir.glob(a.name + ".*")):
        fail(f"{existing[0]} exists; choose another --name")

    if a.backend == "codex":
        src = codex(a.description, a.ref, a.transparent, a.aspect)
        dest = a.out_dir / (a.name + src.suffix)
        shutil.copyfile(src, dest)
        cost = "ChatGPT plan"
    else:
        blob, ext, cost = openrouter(a.description, a.ref, a.transparent, a.aspect, a.model)
        dest = a.out_dir / (a.name + ext)
        dest.write_bytes(blob)

    print(dest)
    print(f"backend={a.backend} cost={cost}")
    if a.transparent and not has_alpha(dest):
        print("warning: the image has no alpha channel; see 'Transparent background' in SKILL.md", file=sys.stderr)


if __name__ == "__main__":
    main()
