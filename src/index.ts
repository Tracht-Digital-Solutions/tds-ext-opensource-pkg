import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Open Source — the projects TDS publishes, and donations to them.
 *
 * Each project has its own small website (the first: wparted.tracht-digital.de).
 * The site keeps its own texts; this screen holds what changes with a release or
 * a decision: version, download link, status line, donation switch and amounts.
 * A save sends the paired site a cache event, so a release needs no deployment.
 *
 * The `settings` slot holds the site connections — one per project. Connecting
 * a site is done once; it does not belong above the daily content.
 */
export default defineExtension({
  id: "opensource",
  name: "Open Source",
  version: "0.1.0",
  permissions: [
    { id: "opensource:read", label: "Open-Source-Projekte und Spenden ansehen", group: "opensource" },
    { id: "opensource:write", label: "Open-Source-Projekte bearbeiten", group: "opensource" },
  ],
  nav: [
    {
      id: "opensource",
      label: "Open Source",
      href: "/open-source",
      // One of the icons tds-core-frontend's Icon.astro actually draws.
      icon: "package",
      // Content: each project is a published page, beside the blog and the cards.
      group: "content",
      order: 150,
      permission: "opensource:read",
    },
  ],
  widgets: [
    {
      id: "opensource-widget",
      title: "Spenden",
      island: "@tracht-digital-solutions/tds-ext-opensource/widgets/Widget.astro",
      size: "sm",
      permission: "opensource:read",
      dataEndpoint: "/opensource/summary",
      order: 150,
    },
  ],
  settings: [
    {
      id: "opensource",
      label: "Open-Source-Seiten",
      island: "@tracht-digital-solutions/tds-ext-opensource/islands/Settings.astro",
      order: 150,
    },
  ],
  routes: [
    {
      pattern: "/open-source",
      entrypoint: "@tracht-digital-solutions/tds-ext-opensource/pages/Index.astro",
      permission: "opensource:read",
    },
  ],
  i18n: {
    de: {
      "opensource.title": "Open Source",
      "opensource.empty": "Noch kein Projekt angelegt.",
    },
    en: {
      "opensource.title": "Open source",
      "opensource.empty": "No project yet.",
    },
  },
});
