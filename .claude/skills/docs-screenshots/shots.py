#!/usr/bin/env python3
"""Capture the documentation screenshots from a throwaway demo copy of OpenGallery.

    python3 .claude/skills/docs-screenshots/shots.py setup [--fresh]   # build or sync the demo copy
    python3 .claude/skills/docs-screenshots/shots.py status            # which images are missing, flagged or unlisted
    python3 .claude/skills/docs-screenshots/shots.py capture NAME ...  # (re)take shots from shots.json (--all for every one)
    python3 .claude/skills/docs-screenshots/shots.py shrink FILE ...   # resize + convert existing images to WebP

The demo copy is a git worktree in storage/docs-screenshots/app with its own SQLite
database (full DemoSeeder), its own storage and a copy of vendor and public/, so the
local gallery is never touched. `setup` syncs it with the working tree, uncommitted
and untracked files included.

Needs: python3 with playwright (+ its Chromium) and Pillow, php, git, rsync.
"""

from __future__ import annotations

import argparse
import io
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
ENV = REPO / "storage" / "docs-screenshots" / "app"
STATE = REPO / "storage" / "docs-screenshots" / "state"
ASSETS = REPO / "docs" / "assets"
SHOTS = HERE / "shots.json"

HOST, PORT = "127.0.0.1", 8765
BASE = f"http://{HOST}:{PORT}"
SHOWN_ADDRESS = "https://photos.example.com"
VIEWPORT = (1440, 900)
SCALE = 2
MAX_WIDTH = 1600  # px of the stored image; the docs column is narrower than this
QUALITY = 80
PASSWORD = "password"


def run(cmd: list[str], cwd: Path = REPO, env: dict | None = None, check: bool = True, quiet: bool = False) -> str:
    result = subprocess.run(cmd, cwd=cwd, env={**os.environ, **(env or {})}, text=True, capture_output=True)
    if check and result.returncode != 0:
        sys.exit(f"failed: {' '.join(cmd)}\n{result.stdout[-2000:]}\n{result.stderr[-2000:]}")
    if not quiet and result.stdout.strip():
        print(result.stdout.strip()[-1500:])
    return result.stdout


def artisan(*args: str, quiet: bool = True) -> str:
    return run(["php", "artisan", *args], cwd=ENV, quiet=quiet)


def php(code: str) -> str:
    """Run PHP in the demo copy (tinker) and return its output."""
    return artisan("tinker", "--execute", code).strip()


# --------------------------------------------------------------------------- setup

ENV_FILE = """APP_NAME=OpenGallery
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL={base}
APP_LOCALE=en
LOG_CHANNEL=single
DB_CONNECTION=sqlite
DB_DATABASE={db}
SESSION_DRIVER=database
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
GALLERY_INSTALLED=true
GALLERY_OPTIMIZE=false
"""


def setup(fresh: bool) -> None:
    ENV.parent.mkdir(parents=True, exist_ok=True)
    if not (ENV / ".git").exists():
        print("creating worktree", ENV)
        run(["git", "worktree", "add", "--detach", str(ENV), "HEAD"], quiet=True)

    # Snapshot the working tree (uncommitted + untracked, ignored files excluded) and check it out in the copy.
    index = STATE / "index"
    STATE.mkdir(parents=True, exist_ok=True)
    index.unlink(missing_ok=True)
    tmp = {"GIT_INDEX_FILE": str(index)}
    run(["git", "add", "-A"], env=tmp, quiet=True)
    tree = run(["git", "write-tree"], env=tmp, quiet=True).strip()
    run(["git", "read-tree", "-u", "--reset", tree], cwd=ENV, quiet=True)
    print("synced code", tree[:10])

    # Composer resolves class paths from vendor's real location, so copy it (no symlink).
    run(["rsync", "-a", "--delete", f"{REPO}/vendor/", f"{ENV}/vendor/"], quiet=True)
    if not (REPO / "public" / "build" / "manifest.json").exists():
        sys.exit("public/build is missing: run `npm run build` in the repo first")
    run(["rsync", "-a", "--exclude", "storage", "--exclude", "hot", f"{REPO}/public/", f"{ENV}/public/"], quiet=True)
    for sub in ["framework/cache/data", "framework/sessions", "framework/views", "logs", "app/private"]:
        (ENV / "storage" / sub).mkdir(parents=True, exist_ok=True)

    db = ENV / "database" / "screenshots.sqlite"
    if fresh:
        db.unlink(missing_ok=True)
        shutil.rmtree(ENV / "storage" / "app", ignore_errors=True)
        (ENV / "storage" / "app" / "private").mkdir(parents=True, exist_ok=True)
        (STATE / "sessions").exists() and shutil.rmtree(STATE / "sessions")

    env_file = ENV / ".env"
    if not env_file.exists():
        env_file.write_text(ENV_FILE.format(base=BASE, db=db))
        artisan("key:generate", "--force")

    artisan("optimize:clear")
    if not db.exists():
        db.touch()
        print("seeding the demo (takes a few minutes) ...")
        artisan("migrate", "--force", "--seed")
        # Roles and the first account are logged at the real time, after the demo's year; they are not part of the story.
        php("App\\Models\\ActivityEvent::query()->delete();")
        artisan("db:seed", "--class=DemoSeeder", "--force")
        php('App\\Models\\Setting::put("jobs.mode", "cron");')
    else:
        artisan("migrate", "--force")
    print("demo copy ready:", ENV)


# --------------------------------------------------------------------------- status

def doc_images() -> dict[str, list[tuple[str, int, bool]]]:
    """image file name -> [(doc, line, has TODO)]"""
    found: dict[str, list[tuple[str, int, bool]]] = {}
    for doc in sorted((REPO / "docs").rglob("*.md")):
        in_code = False
        for number, line in enumerate(doc.read_text().splitlines(), 1):
            if line.startswith("```"):
                in_code = not in_code
            if in_code:
                continue
            for match in re.finditer(r"!\[[^\]]*\]\(([^)]+)\)", line):
                name = Path(match.group(1)).name
                found.setdefault(name, []).append((str(doc.relative_to(REPO)), number, "TODO" in line))
    return found


def status() -> None:
    shots = load_shots()
    images = doc_images()
    rows = []
    for name, uses in sorted(images.items()):
        stem = Path(name).stem
        file = ASSETS / name
        state = "ok" if file.exists() else "MISSING"
        if any(todo for _, _, todo in uses) and file.exists():
            state = "TODO"
        size = f"{file.stat().st_size // 1024}KB" if file.exists() else "-"
        listed = "yes" if stem in shots else "NO"
        where = ", ".join(f"{d}:{n}" for d, n, _ in uses)
        rows.append((state, name, size, listed, where))
    print(f"{'state':8} {'image':42} {'size':>7}  {'in shots.json':13} used in")
    for state, name, size, listed, where in sorted(rows, key=lambda r: (r[0] == "ok", r[1])):
        print(f"{state:8} {name:42} {size:>7}  {listed:13} {where}")
    unused = sorted(p.name for p in ASSETS.iterdir() if p.is_file() and p.name not in images)
    if unused:
        print("\nnot referenced by any doc:", ", ".join(unused))
    unknown = sorted(s for s in shots if not any(Path(n).stem == s for n in images))
    if unknown:
        print("in shots.json but not used by any doc:", ", ".join(unknown))


# --------------------------------------------------------------------------- capture

def load_shots() -> dict:
    data = json.loads(SHOTS.read_text())
    return {k: v for k, v in data.items() if not k.startswith("_")}


def server_up() -> bool:
    with socket.socket() as s:
        return s.connect_ex((HOST, PORT)) == 0


def start_server() -> subprocess.Popen:
    """Start PHP's built-in server for the demo copy in its own process group.

    `php -S` is started directly (not through `artisan serve`, whose workers outlive it
    with a broken log pipe and print notices into every page). variables_order=EGPCS
    makes the server read the copy's environment, display_errors keeps notices out of
    the screenshots, and the request log goes to state/server.log.
    """
    if server_up():
        sys.exit(f"port {PORT} is already in use; stop that process first (lsof -ti tcp:{PORT})")
    log = open(STATE / "server.log", "w")
    router = ENV / "vendor" / "laravel" / "framework" / "src" / "Illuminate" / "Foundation" / "resources" / "server.php"
    proc = subprocess.Popen(
        ["php", "-d", "variables_order=EGPCS", "-d", "display_errors=0", "-S", f"{HOST}:{PORT}", str(router)],
        cwd=ENV / "public", stdout=log, stderr=subprocess.STDOUT, start_new_session=True,
        env={**os.environ, "PHP_CLI_SERVER_WORKERS": "4"},
    )
    for _ in range(100):
        if server_up():
            return proc
        time.sleep(0.1)
    stop_server(proc)
    sys.exit("server did not start, see storage/docs-screenshots/state/server.log")


def stop_server(proc: subprocess.Popen) -> None:
    """Stop the server and all its workers."""
    try:
        os.killpg(proc.pid, signal.SIGTERM)
        proc.wait(timeout=5)
    except (ProcessLookupError, subprocess.TimeoutExpired):
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass


PLACEHOLDER = re.compile(r"\{(album|photo|page|user|share|php):([^}]+)\}")
LOOKUP = {
    "album": 'App\\Models\\Album::withTrashed()->where("title", {v})->firstOrFail()->getRouteKey()',
    "photo": 'App\\Models\\Photo::where("title", {v})->firstOrFail()->getKey()',
    "page": 'App\\Models\\Page::where("title", {v})->firstOrFail()->getRouteKey()',
    "user": 'App\\Models\\User::where("email", {v})->firstOrFail()->getKey()',
    "share": 'App\\Models\\Album::where("title", {v})->firstOrFail()->share_token',
}


def resolve(text: str) -> str:
    def replace(match: re.Match) -> str:
        if match.group(1) == "php":
            return php(f"echo {match.group(2)};")
        return php("echo " + LOOKUP[match.group(1)].format(v=json.dumps(match.group(2))) + ";")
    return PLACEHOLDER.sub(replace, text)


MASK_JS = """([from, to]) => {
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) { const n = walker.currentNode; if (n.nodeValue.includes(from)) n.nodeValue = n.nodeValue.split(from).join(to); }
  document.querySelectorAll('input, textarea').forEach(el => { if (el.value && el.value.includes(from)) el.value = el.value.split(from).join(to); });
}"""

SETTLE_JS = """async () => {
  await document.fonts.ready;
  await Promise.all([...document.images].filter(i => !i.complete && i.loading !== 'lazy').map(i => new Promise(r => { i.onload = i.onerror = r; })));
}"""


def login(browser, email: str):
    path = STATE / "sessions" / f"{email}.json"
    if path.exists():
        return path
    path.parent.mkdir(parents=True, exist_ok=True)
    context = browser.new_context(viewport=dict(zip(("width", "height"), VIEWPORT)))
    page = context.new_page()
    page.goto(f"{BASE}/login")
    page.fill("#email", email)
    page.fill("#password", PASSWORD)
    page.click("form[action$='/login'] button[type=submit]")
    page.wait_for_load_state("networkidle")
    if "/login" in page.url:
        sys.exit(f"login failed for {email}")
    context.storage_state(path=str(path))
    context.close()
    return path


def do_step(page, step: dict) -> None:
    (kind, value), = step.items()
    if kind == "click":
        page.locator(value).first.click()
    elif kind == "hover":
        page.locator(value).first.hover()
    elif kind == "fill":
        page.locator(value[0]).first.fill(value[1])
    elif kind == "type":
        page.locator(value[0]).first.press_sequentially(value[1], delay=40)
    elif kind == "press":
        page.keyboard.press(value)
    elif kind == "wait":
        page.wait_for_timeout(value) if isinstance(value, (int, float)) else page.locator(value).first.wait_for()
    elif kind == "scroll":
        page.locator(value).first.scroll_into_view_if_needed()
    elif kind == "goto":
        page.goto(BASE + resolve(value))
    elif kind == "eval":
        page.evaluate(value)
    else:
        sys.exit(f"unknown step {kind!r}")
    page.wait_for_load_state("networkidle")


def save(png: bytes, target: Path, max_width: int = MAX_WIDTH) -> None:
    from PIL import Image

    image = Image.open(io.BytesIO(png))
    image = image.convert("RGBA" if image.mode in ("RGBA", "LA", "P") else "RGB")
    if image.width > max_width:
        image = image.resize((max_width, round(image.height * max_width / image.width)), Image.LANCZOS)
    image.save(target, "WEBP", quality=QUALITY, method=6)
    print(f"saved {target.relative_to(REPO)}  {image.width}x{image.height}  {target.stat().st_size // 1024}KB")


def launch(playwright):
    """Playwright's own Chromium, or any Chromium / Chrome for Testing already in its cache."""
    try:
        return playwright.chromium.launch()
    except Exception:
        cache = Path.home() / "Library" / "Caches" / "ms-playwright"
        if not cache.exists():
            cache = Path.home() / ".cache" / "ms-playwright"
        candidates = sorted(cache.glob("chromium-*/chrome-*/*.app/Contents/MacOS/*")) + sorted(cache.glob("chromium-*/chrome-linux*/chrome"))
        if not candidates:
            sys.exit("no Chromium found: run `python3 -m playwright install chromium`")
        return playwright.chromium.launch(executable_path=str(candidates[-1]))


def capture(names: list[str], everything: bool) -> None:
    from playwright.sync_api import sync_playwright

    if not (ENV / ".env").exists():
        sys.exit("no demo copy yet: run `shots.py setup` first")
    shots = load_shots()
    names = list(shots) if everything else names
    missing = [n for n in names if n not in shots]
    if missing:
        sys.exit(f"not in shots.json: {', '.join(missing)}")

    server = start_server()
    try:
        # One cron pass so site health starts clean; shots that need a problem stage it in "php".
        php("App\\Support\\BackgroundJobs::run(App\\Enums\\BackgroundJobMode::Cron);")
        with sync_playwright() as p:
            browser = launch(p)
            marker = php('echo (int) App\\Models\\ActivityEvent::max("id");')
            sessions = {e: login(browser, e) for e in {shots[n].get("as") for n in names} if e}
            # Logins and visits made by the capture are not part of the demo story.
            forget = f'App\\Models\\ActivityEvent::where("id", ">", {marker or 0})->delete();'
            php(forget)
            failed = []
            for name in names:
                try:
                    shoot(browser, name, shots[name], sessions)
                except (Exception, SystemExit) as error:
                    failed.append(name)
                    print(f"FAILED {name}: {str(error).splitlines()[0][:300]}")
            browser.close()
            php(forget)
            if failed:
                print("failed:", " ".join(failed))
    finally:
        stop_server(server)


def shoot(browser, name: str, shot: dict, sessions: dict) -> None:
    if shot.get("php"):
        php(shot["php"])
    width, height = shot.get("viewport", VIEWPORT)
    context = browser.new_context(
        viewport={"width": width, "height": height},
        device_scale_factor=SCALE,
        storage_state=str(sessions[shot["as"]]) if shot.get("as") else None,
        color_scheme=shot.get("scheme", "light"),
        reduced_motion="reduce",
    )
    page = context.new_page()
    page.set_default_timeout(8000)
    page.goto(BASE + resolve(shot["url"]))
    page.wait_for_load_state("networkidle")
    for step in shot.get("steps", []):
        do_step(page, step)
    page.evaluate(SETTLE_JS)
    page.evaluate(MASK_JS, [BASE, SHOWN_ADDRESS])
    page.evaluate(MASK_JS, [f"{HOST}:{PORT}", SHOWN_ADDRESS.split("//")[1]])
    page.wait_for_timeout(shot.get("delay", 300))

    options = {"animations": "disabled", "caret": "hide"}
    if shot.get("clip"):
        # One selector, or a list: the crop then covers all of them.
        selectors = shot["clip"] if isinstance(shot["clip"], list) else [shot["clip"]]
        boxes = []
        for selector in selectors:
            box = page.locator(selector).first.bounding_box()
            if box is None:
                sys.exit(f"{name}: clip selector not visible: {selector}")
            scroll = page.evaluate("[scrollX, scrollY]")
            boxes.append((box["x"] + scroll[0], box["y"] + scroll[1], box["x"] + scroll[0] + box["width"], box["y"] + scroll[1] + box["height"]))
        pad = shot.get("pad", 16)
        left, top = max(min(b[0] for b in boxes) - pad, 0), max(min(b[1] for b in boxes) - pad, 0)
        right, bottom = min(max(b[2] for b in boxes) + pad, width), max(b[3] for b in boxes) + pad
        options["clip"] = {"x": left, "y": top, "width": right - left, "height": bottom - top}
        options["full_page"] = True
    elif shot.get("full_page"):
        options["full_page"] = True
    if options.get("full_page"):
        # Sticky bars are drawn where the page is scrolled to; the crop uses page coordinates.
        page.evaluate("window.scrollTo(0, 0)")
        page.wait_for_timeout(200)
    try:
        png = page.screenshot(**options)
        save(png, ASSETS / f"{name}.webp", shot.get("max_width", MAX_WIDTH))
    finally:
        context.close()
        if shot.get("after_php"):
            php(shot["after_php"])


PROBE_JS = """(text) => {
  const out = [];
  const describe = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + [...el.classList].slice(0, 4).map(c => '.' + c).join('')
    + ['aria-label', 'title', 'type', 'name', 'x-on:click', 'wire:click'].filter(a => el.getAttribute(a)).map(a => `[${a}="${el.getAttribute(a).slice(0, 40)}"]`).join('');
  for (const el of document.querySelectorAll('body *')) {
    const own = [...el.childNodes].some(n => n.nodeType === 3 && n.nodeValue.toLowerCase().includes(text.toLowerCase()))
      || (el.getAttribute('aria-label') || el.getAttribute('title') || el.getAttribute('placeholder') || '').toLowerCase().includes(text.toLowerCase());
    if (!own) continue;
    const chain = []; let e = el;
    for (let i = 0; e && i < 5; i++, e = e.parentElement) chain.unshift(describe(e));
    const box = el.getBoundingClientRect();
    out.push(`${box.width ? 'visible' : 'hidden '} y=${Math.round(box.y)}  ${chain.join(' > ')}`);
  }
  return out.slice(0, 15);
}"""


def probe(url: str, email: str | None, texts: list[str]) -> None:
    """Print the elements whose own text (or label) contains each text, with four ancestors, to find selectors."""
    from playwright.sync_api import sync_playwright

    server = start_server()
    try:
        with sync_playwright() as p:
            browser = launch(p)
            context = browser.new_context(viewport={"width": VIEWPORT[0], "height": VIEWPORT[1]},
                                          storage_state=str(login(browser, email)) if email else None)
            page = context.new_page()
            page.goto(BASE + resolve(url))
            page.wait_for_load_state("networkidle")
            for text in texts:
                print(f"--- {text!r}")
                print("\n".join(page.evaluate(PROBE_JS, text)) or "(nothing)")
            browser.close()
    finally:
        stop_server(server)


def sheet(names: list[str], columns: int = 3, width: int = 520) -> None:
    """Draw the given shots (default: all) on one labelled contact sheet, to review them in a single image."""
    from PIL import Image, ImageDraw

    files = [ASSETS / f"{n}.webp" for n in names] if names else sorted(ASSETS.glob("*.webp"))
    files = [f for f in files if f.exists()]
    if not files:
        sys.exit("no images to show")
    thumbs = []
    for f in files:
        image = Image.open(f).convert("RGB")
        image.thumbnail((width, width))
        thumbs.append((f.stem, image))
    cell_h = max(t.height for _, t in thumbs) + 24
    rows = -(-len(thumbs) // columns)
    out = Image.new("RGB", (columns * (width + 12) + 12, rows * (cell_h + 12) + 12), "#888")
    draw = ImageDraw.Draw(out)
    for i, (name, thumb) in enumerate(thumbs):
        x, y = 12 + (i % columns) * (width + 12), 12 + (i // columns) * (cell_h + 12)
        draw.text((x, y + 4), name, fill="white")
        out.paste(thumb, (x, y + 22))
    target = STATE / "sheet.png"
    out.save(target)
    print(target)


def shrink(files: list[str]) -> None:
    for file in files:
        source = Path(file).resolve()
        save(source.read_bytes(), source.with_suffix(".webp"))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)
    s = sub.add_parser("setup")
    s.add_argument("--fresh", action="store_true", help="drop the demo database and seed again")
    sub.add_parser("status")
    c = sub.add_parser("capture")
    c.add_argument("names", nargs="*")
    c.add_argument("--all", action="store_true")
    r = sub.add_parser("probe", help="list elements containing a text, to find selectors")
    r.add_argument("url")
    r.add_argument("texts", nargs="+")
    r.add_argument("--as", dest="email")
    h = sub.add_parser("sheet", help="contact sheet of shots, to review them in one image")
    h.add_argument("names", nargs="*")
    k = sub.add_parser("shrink")
    k.add_argument("files", nargs="+")
    args = parser.parse_args()

    if args.command == "setup":
        setup(args.fresh)
    elif args.command == "status":
        status()
    elif args.command == "capture":
        capture(args.names, args.all)
    elif args.command == "probe":
        probe(args.url, args.email, args.texts)
    elif args.command == "sheet":
        sheet(args.names)
    else:
        shrink(args.files)


if __name__ == "__main__":
    main()
