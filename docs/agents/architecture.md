# Architecture

## Shape

| Path | Role |
|---|---|
| `src/index.ts` | The `defineExtension({...})` manifest: nav `/open-source`, widget, settings, route |
| `pages/Index.astro` + `islands/ProjectsScreen.tsx` | Edit a project, list donations |
| `islands/Settings.astro` + `islands/ConnectionSettings.tsx` | One site pairing per project |
| `widgets/Widget.astro` + `islands/WidgetBody.tsx` | Donations in the last 30 days |
| `php/src/OpensourceModule.php` | Routes, settings, Stripe webhook def, setup items |
| `php/src/Domain/*Repository.php` | Every query |
| `php/src/Support/*` | Pure rules: amounts, Stripe events, checkout parameters, panel input, IP hash |

## Routes and trust

| Prefix | Caller | Auth |
|---|---|---|
| `/opensource/projects/{slug}` | the project site's server | site key (`siteKeyRoutes`) |
| `/opensource/donate/{slug}` | a visitor's browser | none; amount checked, 10 per IP hash and hour |
| `/opensource/stripe-webhook` | Stripe | signature |
| `/opensource/summary`, `/opensource/admin/*` | the panel | `opensource:read` / `opensource:write` |

The site-key middleware matches by **prefix on segment boundaries**. That is why the
browser route and the admin routes do not live under `/opensource/projects`: there they
would need a key, and enforcement would break the donate button and the panel.

## Donations

`POST /opensource/donate/{slug}` creates a Checkout Session with inline `price_data`
(`DonationCheckout::params`) and writes an `open` row. The webhook moves it to `paid`,
`failed` or `expired` (`DonationEvent::fromStripe`). `completed` with `payment_status`
other than `paid` changes nothing; SEPA confirms later.

Return URLs are fixed per language on the project's `site_url`
(`/spenden/danke`, `/en/donate/thanks`, cancel to `/#spenden`, `/en/#donate`). The site
must serve those routes. A URL from the request is never used: that would make the
route an open redirect behind Stripe's checkout page.

`donations.enabled` in the public shape is the project's switch **and** a configured
Stripe account. The site hides or disables its button on `false`.

## Site connection and cache

Resource `opensource/<slug>`, profile `opensource`, scope `/opensource/projects`. The
core API (`SitePairingService`) must know that type and profile. A project save sends
`CacheEvent('project', <slug>)`; the site maps it to its pages. `not_configured` is the
normal answer before pairing and is reported as such.

## Adding a project

Create it in the panel (slug is permanent), build its site from `tds-wparted-frontend`'s
shape, add the site's origin to `SiteKeyPolicy::KNOWN` and CORS in the core API and a
profile in `tds-shared/src/install/profiles.ts`, then pair.
