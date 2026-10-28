# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

| Suite | Covers |
|---|---|
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest as a product build sees it; specifiers resolve, are exported and ship |
| `islands/ProjectsScreen.test.ts` | Euro presets round-trip, honest cache messages |
| `php/tests/SupportTest.php` | Amount rules, Stripe event filter, checkout parameters, panel validation |
| `php/tests/OpensourceModuleTest.php` | Routes on SQLite: public read, donate, webhook signature + settle, permissions, cache report |
| `php/tests/OpensourceApiDocsTest.php` | Documented and mounted routes are the same set |
| `php/tests/OpensourceMigrationsTest.php` | File/class names, own band, unsigned ids, no adapter internals |

The module tests run on SQLite; only portable SQL is used. Anything MySQL-specific belongs in a migration, not in a repository.
