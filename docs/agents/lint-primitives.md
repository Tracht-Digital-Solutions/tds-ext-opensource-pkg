# `lint:primitives` (the tds-shared bin)

Every extension, tool pack, the panel host and two sites run `npm run lint:primitives`, which is
`tds-lint-primitives` — a bin that **tds-shared ships since 0.59** (`scripts/lint-primitives.mjs`
there). Until then each of ~30 repos carried a copy of the script, seeded from this repo and
propagated by hand; the copies had drifted into two variants. The bin is their union.

## Changing it

Change `scripts/lint-primitives.mjs` in **tds-shared**, in the same release as the CSS of the
primitive it starts to allow, then repin the consumers. Never copy the script back into a repo.
CI keeps calling `npm run lint:primitives`; tds-shared is installed as a peer, so the bin is on
the path.

## What it checks

- A control (`button`, `input`, `select`, `textarea`, …) without a shared class.
- A `<table>` without `tds-table`, and a flex/grid table cell (which drops the cell out of
  the column algorithm).
- A `btn-*` variant that doesn't exist in tds-shared. `btn btn-secondary` used to pass while
  matching no rule at all.
- `.tds-dropdown__trigger` / `__item` and the app-shell controls (`tds-tabbar__item`,
  `tds-sheet__close`, `tds-segmented__option`, `tds-tile`, `tds-filterbar__reset|close`,
  `tds-searchfield__input`) count as shared classes.

## How it reads markup

- It is a **regex scan**, so a tag name written inside a comment counts as markup. Name
  elements in prose.
- `readTag()` walks each tag tracking string state and `{}` depth, so an arrow handler's `>`
  (`onClick={() => …}`) no longer ends a tag early.
- `classOf()` resolves a local `const field = "…"`, so the result does not depend on a
  variable's name.

Before "fixing" a false positive, check whether the tag parsing is the cause.
