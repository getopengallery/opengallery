# Changelog

Notable changes to OpenGallery. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
