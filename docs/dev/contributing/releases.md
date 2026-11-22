---
order: 60
---

# Releases and versions

How a change reaches `main`, when it becomes a new version, and how that version is published. Merging a pull request never creates a release: a maintainer decides when to tag one.

## Changing the code

`main` is protected. Every change goes through a pull request:

1. Create a branch from an up-to-date `main`.
2. Commit the change with its tests and docs.
3. Add notable changes to the `Unreleased` section of `CHANGELOG.md`.
4. Push the branch and open a pull request.
5. Merge it once these checks pass: Tests (PHP 8.3 and 8.4), Database backup (MySQL and MariaDB) and Code style.

## Choosing the next version

Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). If a release contains several kinds of change, the highest row decides.

| Change | Next version | Test version first |
| --- | --- | --- |
| Stable and promised for the long term | `1.0.0` | Yes |
| Breaking change for themes or plugins | `0.3.0` during 0.x, a new major version after 1.0 | Yes |
| Change to the updater | At least `0.3.0` | Yes, with a real update |
| Database change | At least `0.3.0` | Yes |
| New feature | `0.3.0` | Yes |
| Bug or security fix | `0.2.1` | Only if it changes the database or the updater |

The examples assume the current version is `0.2.0`.

## Test versions

A test version has a suffix, such as `v0.3.0-rc.1` or `v0.3.0-dev.1`. Only galleries on the **Development** update channel are offered it. Use `-rc.N` for a version that should become the stable release unless testing finds a problem.

The updater of the **installed** version performs an update. To test a change to the updater, install a test version with that change, then update it to the next test version.

## Making a release

For a test version, skip steps 1 and 6.

1. In a pull request, move the `Unreleased` notes in `CHANGELOG.md` under the new version and date, and merge it.
2. Tag the merged `main` and push only the tag:

   ```bash
   git switch main
   git pull --ff-only
   git tag -a v0.3.0 -m "OpenGallery 0.3.0"
   git push origin refs/tags/v0.3.0
   ```

3. Wait for the Release workflow. It runs all checks, builds the zip and creates a **draft** release. Do not create a release by hand.
4. Sign a stable release on the maintainer's machine, as described in [Signing releases](../internals/updates.md#signing-releases). Test versions are signed by the workflow.
5. Check that the draft has the `.zip`, `.zip.sha256` and `.update.json` files, then publish it. A test version must stay marked as a pre-release.
6. In a small pull request, set `VERSION` to the next development version, such as `0.4.0-dev`. Releases take their version from the tag.

## If a release is broken

Never change or replace a published release, and never reuse a tag. Fix the problem and publish a higher version. Galleries that could not update stay on their working version, because a failed update restores itself.

How the zip is built is described in [Shipping a release](frontend-build.md#shipping-a-release).
