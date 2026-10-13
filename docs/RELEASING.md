# Releasing

Versions follow [Semantic Versioning](https://semver.org/); while the version is 0.x, a minor version may break the
public API (ARCHITECTURE §4), and the CHANGELOG says how. Releases are cut from `main`.

## Checklist

1. **Green build**: `main` is up to date and `composer check` passes locally and in CI.
2. **CHANGELOG**: move the entries of `[Unreleased]` into a new `## [X.Y.Z] - YYYY-MM-DD` section (the tag date),
   with a one-line summary and links to the ADRs of new decisions. Update the link references at the bottom:
   `[Unreleased]` compares `vX.Y.Z...HEAD`, `[X.Y.Z]` points to the release tag.
3. **Version constraints in the docs**: the `composer require mtubis/html-translator:^X.Y` line in the
   [README](../README.md) and any other constraint in `docs/` (`grep -rn "html-translator:\^" README.md docs`).
4. **Roadmap**: mark the milestone as done in [ROADMAP](ROADMAP.md) and close the GitHub milestone.
5. **Commit and merge** these changes (`docs: release X.Y.Z`) through a pull request.
6. **Tag and publish** (maintainer only): tag the merge commit on `main` with `vX.Y.Z`, push the tag, create the GitHub
   release from the CHANGELOG section and check that Packagist picked up the version. If the tag is created on a
   later day than the CHANGELOG date, correct the date first.

```bash
git switch main && git pull
git tag -a vX.Y.Z -m "X.Y.Z"
git push origin vX.Y.Z
```
