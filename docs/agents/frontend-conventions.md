# Frontend conventions

An extension ships **no CSS**. Every token and component comes from `tds-shared`, which
the product installs (declared here as a **peer** dependency, like astro and react).
The host renders this markup inside the `panel` surface, so geometry is already decided.

## Shared classes, never invented names

| Slot | Class |
|---|---|
| page shell | `tds-page` + `tds-page__head` > `h1.tds-page__title` (+ `tds-page__lede`) |
| dashboard widget | `article.tds-widget` > `h3.tds-widget__title`; a count body is `<SummaryMetric endpoint>` (`—` on failure) |
| settings slot | `div.tds-settings-section__body` |
| record list | `ul.tds-list` > `li.tds-list__row` |
| card / table / empty | `tds-card` · `tds-table` · `tds-empty` |
| button | `btn` + `btn-primary` / `-accent` / `-ghost` / `-danger` (**both** classes) |
| inline label | `chip` + `chip--{neutral,success,warning,danger,info,cat-*}`; a status is `<StatusChip status map>` |
| block message | `<Alert tone>` from `tds-shared/components` (renders `tds-alert`); never hand-written |
| label + control | `tds-field-row` · toggle row `tds-toggle-row` |
| message thread | `tds-thread` > `tds-thread__item--own` / `--other` |
| loading | `<Spinner />` from `tds-shared/components` |
| destructive confirm | `<ConfirmDialog />` from `tds-shared/components`, **never `window.confirm()`** |
| tabs / choice | `<Tabs>` + `<TabPanel>` · `<Segmented>` from `tds-shared/components` |
| dates, money, sizes | `tds-shared/format` (`formatDate`, `formatCents`, `parseEuros`, `formatBytes`) |
| copy, download, upload | `tds-shared/clipboard` · `/download` · `<FileDropZone>` from `/dropzone` |

Internal layout: `.tds-stack` (+ `--tight` / `--loose`) for vertical stacks, `.tds-row`
(+ `--between`) for wrapping rows, `.tds-compose` (+ `__actions`) for a reply box,
`.tds-toolbar` for action rows, `.marginalia` for metadata and hints.

A bespoke BEM name has no CSS rule anywhere and renders on browser defaults. Only add
one for a genuinely singular internal, knowing it stays unstyled.

## Traps

- **Never interpolate a class name** (`` `chip chip--${status}` ``). Tailwind can't
  extract it, and an unknown value renders unstyled. Map explicitly with a fallback; for
  database values use `resolveChipVariant()` from `@tracht-digital-solutions/tds-shared/design`.
- **`.status-pill` is an inline label, not a banner.** Use `.tds-alert` for a block message.
- **A JSX comment can't sit in an expression position** (after `=> (`, in a ternary
  branch, in a `.map()` return). Put the note above the `return`, or the build fails
  with a bare `Expected ")"`. Same for multi-line `{/* … */}` in an `.astro` body.

## API calls: `apiFetch`, never a relative `fetch`

```tsx
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
```

A relative `fetch` resolves against the product's own host, whose SPA fallback answers
unknown paths with **200 + HTML**. `res.ok` is true, `res.json()` throws, and
`.catch(() => setRows([]))` renders a calm, permanent empty state with no error.
`apiFetch` resolves the base from `<meta name="tds-api-base">` (written by the frontend
host) and routes 401s through the host's confirm-against-`/me` backstop.

A mocked-fetch test can't catch this regression, because a relative path satisfies
every behavioural assertion. Keep at least one assertion per suite on the **absolute host**.

A JSON body goes through `sendJson(path, method, body)`; failure text through
`describeFailure(res | null, action)` from the same module. Never alias `const api = apiFetch`.

## Mutation outcomes: toasts

```tsx
import { toast } from "@tracht-digital-solutions/tds-shared/components";
```

- **Never `await` a mutation and drop the response.** Otherwise a 403 looks like
  success. Optimistic UI must roll back on failure.
- **Failure messages carry the HTTP status.**
- **Transient outcome → toast. Persistent state → in-flow `.tds-alert`.** Load
  failures, form validation and "X is not configured" hints stay in the flow. Anything
  the user must read or copy (a temporary password, a one-time link) never goes in a toast.
- **Never mount a `ToastHost`.** The frontend host owns the only one.
- A failure-only banner uses `.tds-alert--danger`.

## Destructive actions

A destructive action needs a controlled `<ConfirmDialog>`: park the target in state from
the row button, let the dialog perform the action, and pass `busy` while the request runs
so it can't be double-submitted. Grep `method: "DELETE"` when you add one.

## Mobile layout

- **A row of more than two things, or any row with a full-width field, goes on
  `.tds-row`, `.tds-list__row` or `.tds-toolbar`.** All three wrap. A hand-rolled `flex`
  doesn't, and at 375 px `body { overflow-x: hidden }` clips the overflow invisibly.
- **A `<table>` needs `tds-table` and nothing else.** It becomes a horizontal scroller
  below 40rem. A table with no focusable cell also needs `tabindex="0"`, `role="region"`
  and a label so its scrollport is keyboard-reachable.

## `lint:primitives`

`npm run lint:primitives` enforces the class rules, including a `<table>` without
`tds-table`, a flex/grid table cell and a `btn-*` variant that doesn't exist in tds-shared.
It is a **regex scan**, so a tag name written inside a comment counts as markup; name
elements in prose. The script is tds-shared's
`tds-lint-primitives` bin (since 0.59); change it there, never in a local copy (see
`docs/agents/lint-primitives.md`).

## Routes inside the host

Extension routes are wrapped in the host's layout (`frontendHost({ layout })`). A page
renders only its `<section>`, never a full `<html>`.

## Usage snippets

```tsx
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
import { toast } from "@tracht-digital-solutions/tds-shared/components";

// apiFetch sends the session cookie and resolves the API base; never alias it.
const res = await apiFetch("/thing", { method: "PUT", body });
if (res.ok) toast.success("Gespeichert.");
else toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
```
