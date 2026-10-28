# Release and CI

Dual package: the TypeScript half publishes to GitHub Packages, the PHP half is released
by Git tag (the Composer release ref).

| Workflow | Trigger | Result |
|---|---|---|
| `ci.yml` | pull request | build and test only |
| `dev.yml` | push to `main` | prerelease `<version>-dev.<run>` under the `dev` dist-tag |
| `release.yml` | manual button (patch / minor / major) | `@latest` + annotated tag |

- `release.yml` bumps `package.json` **and** `composer.json` in lockstep, commits, tags
  and publishes. Don't bump versions by hand.
- The tag is **annotated** (`git tag -a`). `git push --follow-tags` only pushes annotated
  tags, and Composer resolves this package by tag.
- CI installs with **`npm install --no-package-lock`**, never `npm ci` with a committed
  lockfile (a win32 lockfile breaks the Linux runner).
- `PACKAGE_TOKEN` installs the contract and publishes this package; CI sets `NPM_TOKEN`
  from it.
- Pruning old package versions (newest 5 prereleases, 10 stable) is `continue-on-error`
  housekeeping and needs `delete:packages` on the token.
- tds-shared stays declared as a **peer** dependency. Omitting it still builds (the
  product's copy resolves), which hides the omission until someone installs standalone.
- Products pin extensions with a 0.x caret, which is minor-locked. A minor bump here
  needs a repin in the consuming product.
