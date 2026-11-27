---
name: docs-screenshots
description: Take or retake the documentation screenshots in docs/assets from a throwaway demo copy, by name, without opening a browser by hand. Use when a doc page needs a new image, an image is missing (TODO placeholder) or a UI change made one stale.
---

# Documentation screenshots

Every image in `docs/assets` (except `setup-welcome`) is described in `shots.json`: which page, which user, what to click and what to crop. `shots.py` takes it from a demo copy with the DemoSeeder data and saves a small WebP (max 1600 px wide, quality 80). Retaking an image is one command; never take screenshots by hand or open many full images.

```bash
S=.claude/skills/docs-screenshots/shots.py
python3 $S setup            # once per session: sync the demo copy with the working tree (--fresh re-seeds)
python3 $S status           # images that are MISSING / TODO, not in shots.json, or unused
python3 $S capture NAME ... # retake these (or --all); failures are listed at the end, the rest still run
python3 $S sheet NAME ...   # one labelled contact sheet: storage/docs-screenshots/state/sheet.png
python3 $S probe URL TEXT... [--as EMAIL]   # find selectors: elements containing TEXT, with 4 ancestors
python3 $S shrink FILE ...  # convert a hand-made image to WebP at the same limits
```

`setup` must run after code changes (it copies uncommitted files too). The demo runs on `http://127.0.0.1:8765`, shown as `https://photos.example.com`. Log entries the capture causes are removed again. Demo users all use the password `password`: `admin@example.com`, `mira@example.com`, `jonas@example.com`, `priya@example.com`, `hana@example.com` (see `docs/dev/contributing/demo-data.md`).

## Adding a shot

1. Reference the image in the doc as `../../assets/{area}-{action}.webp`.
2. Add an entry to `shots.json` under the same name (copy a similar one).
3. `capture NAME`, then look only at `sheet NAME` (one small image) to check it. If a selector times out, `probe` the page for the text you need instead of reading Blade or Filament sources.
4. Remove the `<!-- TODO: screenshot ... -->` comment from the doc line.

## Entry fields

| Field | Meaning |
| --- | --- |
| `url` | Path to open. Placeholders: `{album:Title}` (route key), `{photo:Title}`, `{page:Title}`, `{user:email}`, `{share:Album title}` (share token), `{php:EXPR}` (any PHP expression, echoed) |
| `as` | E-mail of the demo user to log in as, `null` for a guest |
| `viewport` | `[width, height]`, default `[1440, 900]` at 2× scale |
| `scheme` | `"dark"` for the dark colour scheme |
| `php` / `after_php` | PHP run in the demo before the shot / always after it (stage data, then undo setting changes) |
| `steps` | List of `{click|hover|scroll: selector}`, `{fill|type: [selector, text]}`, `{press: key}`, `{wait: ms or selector}`, `{goto: url}`, `{eval: js}` |
| `clip` | Selector or list of selectors; the image is cropped to the box around all of them |
| `pad` | Space around the clip in CSS px (default 16) |
| `full_page` | Whole scrolling page instead of the window |
| `delay` | Extra ms before shooting |
| `max_width` | Smaller width limit for small crops |

Selectors are Playwright selectors (`:has()`, `:text-is()`, `>> nth=1`, `>> visible=true`). Filament section headings are `h2`; a section is `section.fi-section:has(h2[id*='…'])`. For a section that stretches to the row height, clip to its `> header` plus its `.fi-section-content`.

## Watch out

- `setup-welcome` only exists on a fresh install: take it by hand and run `shrink` on it.
- If every image shows a PHP notice or half-empty pages, an old server is still on port 8765: `lsof -ti :8765 | xargs kill`, then capture again.
- Settings changed in `php` stay in the demo database unless `after_php` resets them; `setup --fresh` starts over.
