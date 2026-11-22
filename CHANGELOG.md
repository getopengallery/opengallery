# Changelog

Notable changes to OpenGallery. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Albums and photos can be tagged. Album tags also apply to the photos in the album and, when chosen per album, to its sub-albums. The tag field offers existing and similar tags in a dropdown while typing, and the search on the site finds albums and photos by tag. **Settings → Tags** turns tagging on or off, controls new tags and suggestions, and holds predefined tags.

### Changed

- The admin menu now lists every settings page directly, in a **Settings** and a **System** group, instead of a separate menu inside Settings. The activity log's settings open with a **Log settings** button on the log.
- All documentation screenshots were retaken from the current version, the missing ones were added, and the README now shows the OpenGallery logo and new screenshots.

## [0.2.0] - 2026-11-22

First public release.

### Added

- The installed version is shown at the bottom of the admin menu for people who manage settings.
- Licence, security policy, contribution guide, third-party notices and issue templates.
- Automated tests on PHP 8.3 and 8.4.
- Optional update checks with stable and development channels, chosen during setup and under Settings → Updates.
- One-click updates for super administrators, with signed releases, a database backup and automatic rollback.

### Changed

- OpenGallery is now licensed under the GNU Affero General Public License v3.0 or later.
- Release archives no longer contain files that are only needed to build the frontend.
- Releases are created as drafts with a SHA-256 checksum.

### Fixed

- Release archives now run on PHP 8.3 as documented. Earlier archives needed PHP 8.4.1 or newer.

[Unreleased]: https://github.com/getopengallery/opengallery/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/getopengallery/opengallery/releases/tag/v0.2.0
