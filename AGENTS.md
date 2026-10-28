# AGENTS.md — tds-ext-opensource-pkg

**Open Source.** The open-source projects TDS publishes, each with its own small website,
and donations to them through the central Stripe account. This package owns the data,
the API and the panel screen. It is **not** a site: the first one is
`tds-wparted-frontend` (wparted.tracht-digital.de).

Read `tds-frontend-contract-pkg/AGENTS.md` and `tds-core-frontend-api/AGENTS.md` first.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit (SQLite, no MariaDB needed)
```

## Hard rules

- `siteKeyRoutes()` is exactly `['/opensource/projects']`. The browser's donate route and the admin routes stay **outside** that prefix.
- Public reads filter `published` **in SQL**. The public shape never carries ids or timestamps.
- Amounts are integer cents; `DonationAmount` is the only place that judges one. Floats are refused.
- Only Checkout Sessions with `metadata.module = opensource` are ours; every other verified event gets 200 and is ignored.
- `oss_donation` stores no name, e-mail or raw IP. `paid` is final.
- Time windows compare database time with database time (`DonationRepository::cutoff`).
- Migrations: band `20261028`, file name maps to class, unsigned `*_id`, no adapter internals, never delete one.
- `apiFetch`/`sendJson`, never a relative `fetch`. No CSS. Never mount a `ToastHost`.
- Stay in the `0.1.x` line. Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, donations, the site connection or the panel screen |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/lint-primitives.md](docs/agents/lint-primitives.md) | Chasing a lint finding |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
