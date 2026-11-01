# tds-ext-opensource-pkg

Open-Source-Projekte von Tracht Digital Solutions: je Projekt eine kleine Webseite, im
Panel gepflegt, mit Spendenknopf über das zentrale Stripe-Konto.

Eine TDS-Panel-Erweiterung mit zwei Hälften in einem Repository: das
Frontend-Manifest samt Oberfläche (npm, `@tracht-digital-solutions/tds-ext-opensource`)
und das Backend-Modul (Composer, `tracht-digital-solutions/tds-ext-opensource`).

## Was hier liegt und was nicht

Die Webseite eines Projekts hat ihre Texte selbst. Hier liegt nur, was sich mit einem
Release oder einer Entscheidung ändert:

- Version, Datum, Download-Adresse, eine Statuszeile (DE/EN)
- Quellcode, Spiegel, Issues, Lizenz
- Spenden an/aus, Vorschlagsbeträge, Grenzen, Währung

Speichern schickt der gekoppelten Webseite ein Cache-Ereignis. Ein Release kostet
damit eine Zeile im Panel, keinen Deploy.

Erstes Projekt: **WParted** (`wparted`), Seite `tds-wparted-frontend` auf
`wparted.tracht-digital.de`. Eine Migration legt es an, eine zweite bringt es auf den Stand der
Beta 0.8. Die Webseite zeigt die Felder Spiegel und Issues nicht an: Fehler laufen dort über
Formular und E-Mail.

## Spenden

1. Besucher wählt auf der Projektseite einen Betrag.
2. Der Browser ruft `POST /opensource/donate/{slug}` auf; die API legt eine
   Stripe-Checkout-Session an (`submit_type=donate`, Metadaten `module=opensource`).
3. Stripe meldet das Ergebnis an `POST /opensource/stripe-webhook`; erst dann zählt
   die Spende als bezahlt.

Gespeichert werden Betrag, Währung, Sprache und Status. Kein Name, keine E-Mail.

**Einrichtung:** In Stripe einen Webhook-Endpunkt `https://api.tracht-digital.de/opensource/stripe-webhook`
mit den Ereignissen `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
`checkout.session.async_payment_failed` und `checkout.session.expired` anlegen. Das
Signing-Secret unter Einstellungen → Zahlungen eintragen. Danach im Panel unter
Open Source „Spenden annehmen“ einschalten.

## Kopplung der Webseite

Einstellungen → Open-Source-Seiten → Kopplung anlegen. Ressource `opensource/<slug>`,
Profil `opensource`, Scope `/opensource/projects`.

## Versionen

| Werkzeug | Version | Wo festgelegt |
|---|---|---|
| PHP | ≥ 8.1, CI mit 8.3 | `composer.json`, `.github/workflows/_build.yml` |
| Node.js | 22 in der CI | `.github/workflows/_build.yml` |
| npm | 11 | CI installiert `npm@11` |
| Slim | ^4.13 | `composer.json` |
| tds-frontend-contract | ^1.13 | `composer.json` |
| PHPUnit | ^10.5 | `composer.json` |
| tds-shared (Peer) | ≥ 0.59.1 | `package.json` |
| Astro (Peer) | ≥ 6 | `package.json` |
| React (Peer) | ≥ 18 | `package.json` |
| TypeScript | ^6.0.3 | `package.json` |
| Vitest | ^4.1.11 | `package.json` |
| Datenbank | MySQL 8 in Produktion | `docs/agents/backend.md` |

## Lizenz / License

**Öffentlich einsehbar, alle Rechte vorbehalten.** Siehe [LICENSE](LICENSE).
Die Projekte selbst (z. B. WParted) haben ihre eigenen, freien Lizenzen.

**Publicly viewable, all rights reserved.** See [LICENSE](LICENSE). The projects
themselves (e.g. WParted) carry their own free licences.
