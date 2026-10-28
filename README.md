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
`wparted.tracht-digital.de`. Eine Migration legt es an.

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

## Lizenz / License

**Öffentlich einsehbar, alle Rechte vorbehalten.** Siehe [LICENSE](LICENSE).
Die Projekte selbst (z. B. WParted) haben ihre eigenen, freien Lizenzen.

**Publicly viewable, all rights reserved.** See [LICENSE](LICENSE). The projects
themselves (e.g. WParted) carry their own free licences.
