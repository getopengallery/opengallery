---
order: 40
---

# Demo data

The demo seeder fills a local installation with content that shows every part of OpenGallery. Use it for development, design work and screenshots, never on a live site.

```bash
php artisan migrate --seed                 # roles, admin@example.com and test@example.com
php artisan db:seed --class=DemoSeeder     # demo content (takes a few minutes)
```

`DemoSeeder` also runs `RolesAndPermissionsSeeder` and creates `admin@example.com` if it is missing, so it can run on its own.

The [setup wizard](../internals/setup-wizard.md) offers the demo as its last step. It sets `DemoSeeder::$administrator` to the super administrator it just created, who then plays the admin (no `admin@example.com`), and scales the photos down to about 30 so the seeder finishes within one web request. The seeder creates its accounts without the model factory, because release installs have no Faker.

## Accounts

Every demo account uses the password `password`.

| E-mail | Name | Role |
| --- | --- | --- |
| `admin@example.com` | Admin | super-admin |
| `mira@example.com` | Mira Okafor | admin |
| `jonas@example.com` | Jonas Lindqvist | moderator |
| `priya@example.com` | Priya Raman | uploader |
| `tomas@example.com` | Tomás Silva | user, later also uploader |
| `hana@example.com` | Hana Sato | user |
| `leo@example.com` | Leo Novak | user (no profile picture) |
| `sofia@example.com` | Sofia Rossi | user (no profile picture) |

Groups: **Team** (Mira, Jonas, Priya, later Tomás) and **Clients** (Hana, Sofia). Profile pictures are generated locally and stored through `App\Support\Avatars::store()`.

## Albums

About 300 generated photos. Each album demonstrates a feature:

| Album | Shows |
| --- | --- |
| Landscapes | Public, manual order (photos dragged into place) |
| Landscapes / Mountains | Sub-album, sorted by date taken (newest first) |
| Landscapes / Coastlines | Sub-album, sorted by date taken (oldest first), edited description |
| City nights | Order changed from manual to title |
| Forest trails | Public album owned by an uploader |
| Archive 2025 | Registered users, sorted by upload date, three photos in the trash |
| Team offsite 2026 | Shared with the Team group only |
| Product launch | Registered users with an album password (`demo`) |
| Editorial picks | Shared directly with two users |
| Client preview: Harbour Hotel | Unlisted share link that expires 30 days after seeding |
| Drafts | Private |
| Best of 2026 | Photos reused from other albums (photos in several albums) |

The top-level albums have a custom order. "Unsorted uploads" holds a few fresh uploads, and "Page images" holds the photos used on the demo pages. Photos have a date taken and sample camera data, so the date sorts and the photo details work. The seeder prints the login list, the album password and the share link when it finishes.

## Tags

Family, Nature, Travel and Events are predefined. Most albums have tags set by their owner, and every fourth photo in Landscapes, Mountains, Coastlines, Forest trails, City nights and Product launch has a tag of its own. Landscapes passes its tags on to Mountains and Coastlines. Global tag suggestions stay off, so each account is offered only the tags it can reach.

## Pages

`DemoPagesSeeder` (called by `DemoSeeder`, or on its own) creates About, Imprint, Privacy, the members-only "Photo walk guide" and an unpublished draft, and links them from the default theme's header and footer if no links are set. With photos from `DemoSeeder`, About contains a photo block and the members-only page a gallery block.

## Activity

The seeder replays a year: it moves the clock back and makes each change as the person who did it, so `App\Support\ActivityLog` writes the change log exactly as in the admin (album and user changes, shares, members, roles, uploads per batch, reorders, trash). On top come administration entries (site name, theme, the example plugin turned on and off), security entries (failed logins, a lockout, a password reset, a wrong album password) and a year of logins and album views, including anonymous guest visits. No IP addresses are stored.

## Running it again

The seeder is safe to re-run. Users are matched by e-mail, groups and albums by name. Photos are only generated for demo albums that are empty, tags are only added to demo albums without tags, and the year of visits and security entries is added only once (when no demo account has logged in yet). To start over, run `php artisan migrate:fresh --seed` and seed the demo again.

## Code

- `Database\Seeders\DemoSeeder`: people, albums, changes and history.
- `Database\Seeders\Demo\DemoImages`: draws the photos (six motifs) and profile pictures with GD; no network access or bundled images.
- `DemoSeeder::$photoScale`: scales the number of photos; the tests and the setup wizard use a fraction.
- `DemoSeeder::$administrator`: an existing super administrator to play the admin instead of `admin@example.com`.
