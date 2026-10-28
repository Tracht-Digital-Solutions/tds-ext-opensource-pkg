# Backend

The PHP half is a `Module` (`php/src/OpensourceModule.php`, extends `AbstractModule`) that the
composed `tds-core-frontend-api` mounts in one process with every other extension.

## Migrations (Phinx)

All composed extensions share **one** `phinxlog`, so:

- The migration **class name** and the **numeric version prefix** must be unique across
  every extension. Put the module id in the class name.
- The **file name must map to the class** (`<version>_<snake_case_class>.php`). A
  mismatch throws `Could not find class …` during the scan and aborts **every**
  extension's migrations, not just this one.
- Migrations must survive MySQL 8 (production): no nullable primary-key columns, and
  unsigned ids where the other side is unsigned.

## API reference (`php/docs/api.php`)

The module implements the contract's optional `ApiDocSource`. `php/docs/api.php` returns
one entry per route (summary, params, responses, required permission). The admin
frontend's API reference joins it onto the introspected Slim routes by
`"<METHOD> <pattern>"`.

- **`pattern` must be the Slim pattern verbatim**, inline regex included. A prettified
  path silently produces an orphan doc and an undocumented route.
- **`php/tests/OpensourceApiDocsTest.php` asserts both directions:** documented and registered
  routes are the same set, every path placeholder is described, and a named permission
  exists in `permissions()`. Adding or renaming a route without touching `docs/api.php`
  fails there.

## Contract dependency

The published `tds-frontend-contract` is consumed VCS-only on the Composer side
(`type: vcs`) and from GitHub Packages on the npm side. Never use a `path` repository;
Composer fails on a missing path repo in CI.

## This module

- Migration band `20261028` (`OpensourceMigrationsTest` pins it and the claimed bands of the others).
- `oss_project`, `oss_donation`; no foreign keys across modules, unsigned `project_id`.
- The seed migration `OpensourceSeedWparted` is idempotent and uses the table API only.
- Repositories are bound unconditionally in `register()` (PHP-DI `has()` is always true for concrete classes).
- `DonationRepository::cutoff()` reads `CURRENT_TIMESTAMP` from the database, so a window never mixes PHP and MySQL clocks — and stays portable SQL for the SQLite tests.
