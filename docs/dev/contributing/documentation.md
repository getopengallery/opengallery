---
order: 50
---

# Writing documentation

The documentation is a set of Markdown files in `/docs`. It ships with every release and is rendered in the admin by the **Documentation** page (`App\Filament\Pages\Documentation`, navigation group "Help").

## Folder layout

| Path | Contents |
| --- | --- |
| `docs/README.md` | Welcome page, opened by default |
| `docs/user/` | Anything done through the running application (admins and users) |
| `docs/dev/` | Anything done in code or JSON (themes, plugins, core) |
| `docs/assets/` | Screenshots and other images |

Decide where a page belongs by who acts on it: someone clicking through the admin reads `user/`, someone writing code or JSON reads `dev/`. There is no third audience folder.

Inside `user/` and `dev/`, pages are grouped in sub-folders by topic, for example `user/content/albums.md` or `dev/extending/themes.md`. Add a sub-folder only for a group of related pages a reader would look for together. Sub-folders may be nested, but one level inside each guide keeps the navigation readable.

## Order and titles

Nothing about the structure is written in code. Each page says where it goes in a front matter block at the very top of the file:

```markdown
---
order: 20
---

# Photos and the media library
```

`App\Support\Documentation` scans `/docs` and every sub-folder (folders without Markdown files, such as `assets`, are skipped) and builds the navigation from what it finds:

- **Pages** are sorted by `order` within their folder, smallest first. Pages without an `order` come after the others, alphabetically by title. Number in steps of 10, so a new page fits in between (`order: 15`) without renumbering its neighbours.
- **Folders** take their place and label from their `README.md`: its `order` places the folder among the pages and folders next to it, and its first `#` heading is the label in the navigation, which links to the README. A folder without a README is labelled after its name (`extra-notes` becomes "Extra notes") and comes last.
- `user/README.md` and `dev/README.md` place the two guides the same way (`order: 10` and `order: 20`).
- The **page title** is the first `#` heading (the file name if there is none).
- `docs/README.md` is always first and needs no `order`. Every other page must have one; `tests/Feature/System/DocumentationTest.php` checks this.

The front matter is plain `key: value` lines between two `---` lines (no nesting or lists). It is removed before the page is shown and is not searched. Only `order` is used at the moment.

A page is addressed by its path below `/docs` without `.md`, for example `/admin/documentation?doc=user/content/albums`. Only files found by the scan can be opened; unknown names return a 404. The README files describe their folder for readers; they no longer decide the order, so moving a page is a matter of changing its `order` (and its links, if it changes folder).

## How the page uses the structure

The Documentation page derives everything a reader navigates by from the files, so there is nothing else to maintain:

- Each top-level folder (`user/`, `dev/`) is a **guide**; the contents show one guide at a time, with a switch labelled by the guides' README headings.
- Topic folders fold open and closed. The one holding the open page starts open.
- The open page's `##` headings are listed below its link as **On this page** (`Documentation::outline()`), so give long pages `##` sections with short, distinct headings. `###` headings are not listed.
- **Previous** and **Next** follow the reading order within the same guide (`Documentation::neighbours()`), the same order as the contents.
- The breadcrumbs above the title are the folders that lead to the page (`Documentation::trail()`), linked to their READMEs.

Links to other docs, inside a page or in the contents, are followed in place: the page's `open()` Livewire action swaps the doc without a page load and `doc` is a URL parameter with browser history, so the address, bookmarks and the back button keep working.

## Markdown rules

Pages are rendered with CommonMark plus the GitHub extensions (tables, strikethrough, task lists, autolinks).

- One `#` heading per file, then `##` and `###` without skipping levels.
- Fenced code blocks with a language tag (`bash`, `php`, `json`, `blade`).
- Raw HTML is stripped when rendering, so use Markdown only. HTML comments, such as screenshot TODOs, do not appear on the page.
- Keep table cells to plain inline content.

## Links and images

Write links relative to the current file, exactly as they work when browsing the repository. From `docs/user/content/albums.md`:

```markdown
See [Site settings](../administration/settings.md#albums) and ![Upload dialog](../../assets/albums-upload.webp)
```

When rendered, links to other `.md` files point to the Documentation page (fragments included) and images point to the `docs.asset` route, which serves PNG, JPEG, GIF and WebP files from `docs/assets` to users who can access the admin. Both use host-relative URLs, so they work whatever address the admin is opened on. Absolute URLs and `#fragment` links are left as they are.

`tests/Feature/System/DocumentationTest.php` checks every relative link, section anchor and image in `/docs`. When you move or rename a page, or change a heading other pages link to, update those links in the same change.

## Screenshots

Store images in `docs/assets/` as WebP with lowercase, hyphenated names like `albums-upload.webp` (`{area}-{action}.webp`). They show the [demo data](demo-data.md) in a browser window of 1440 × 900 at 2× pixel density, so every screenshot has the same scale, and are stored at most 1600 px wide so the pages load quickly. Never show real personal data or secrets.

The screenshots are taken by a script, not by hand. `.claude/skills/docs-screenshots/shots.json` describes each image (page, demo user, clicks, crop), and `shots.py` takes it from a separate demo copy in `storage/docs-screenshots/`, so your own gallery is never touched. It needs Python 3 with Playwright and Pillow:

```bash
python3 .claude/skills/docs-screenshots/shots.py setup               # build or update the demo copy
python3 .claude/skills/docs-screenshots/shots.py status              # missing, flagged and unlisted images
python3 .claude/skills/docs-screenshots/shots.py capture albums-list  # retake one image (--all for every one)
```

`.claude/skills/docs-screenshots/SKILL.md` lists every field. The welcome screen of the setup (`setup-welcome.webp`) is the only image taken by hand; convert such images with `shots.py shrink`.

When a screenshot is still missing, reference it anyway and add a TODO comment next to it so it can be captured later. Until the file exists, the page shows a dashed "Screenshot missing" note with the image's alt text in its place instead of a broken image:

```markdown
![Upload dialog](../../assets/albums-upload.webp) <!-- TODO: screenshot of the upload dialog -->
```

Only images with such a TODO comment on the same line may point to a file that does not exist yet.
