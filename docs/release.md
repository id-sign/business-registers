# Release

- Versions follow semver; the tag `vX.Y.Z` decides the version. Never set `version` in `composer.json`.
- In `0.x`, a minor release may break the public API. Everything under `Internal/` namespaces is `@internal` and has
  no BC promise.
- `UPGRADE.md` lists, per version, every change a consumer has to adapt to (removed or renamed API, changed signatures
  or behaviour), newest first. It is updated in the same commit as the change. Additive changes are not listed there.

## Steps

1. CI is green on `main` and the live suite passed.
2. `UPGRADE.md` has a section for the new version if the release breaks anything.
3. Tag `vX.Y.Z` and create a GitHub release titled `vX.Y.Z — summary` with the list of changes.
4. Packagist picks the version up from the tag.

## Status

Public repository `github.com/id-sign/business-registers`, branch `main`. Still to do by the maintainer: the Packagist
registration, the tag `v0.1.0` and the first release.
