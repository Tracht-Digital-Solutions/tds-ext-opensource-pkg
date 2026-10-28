import { describe, expect, it } from "vitest";
import { composeExtensions } from "@tracht-digital-solutions/tds-frontend-contract";
import manifest from "./index";

/**
 * The manifest is this package's public contract: a product repo folds it into
 * a single build via `composeExtensions`, which hard-errors on any cross-
 * extension id / nav / widget / route collision (the FE twin of the shared
 * `phinxlog` rule). Everything asserted here fails far from this repo if it
 * regresses — in someone else's product build.
 */

describe("identity", () => {
  it("declares the id the product composes it under", () => {
    expect(manifest.id).toBe("opensource");
  });

  it("uses a slug-safe id — it becomes part of nav keys and route paths", () => {
    expect(manifest.id).toMatch(/^[a-z][a-z0-9-]*$/);
  });

  it("has a human name and a semver version", () => {
    expect(manifest.name).toBeTruthy();
    expect(manifest.version).toMatch(/^\d+\.\d+\.\d+$/);
  });

  it("composes standalone — it declares no dependsOn", () => {
    // Standalone: it mounts into no other extension.
    expect(manifest.dependsOn ?? []).toHaveLength(0);
  });
});

describe("permissions", () => {
  it("declares a read and a write permission", () => {
    expect(manifest.permissions?.map((p) => p.id)).toEqual(["opensource:read", "opensource:write"]);
  });

  it("namespaces every permission id", () => {
    for (const p of manifest.permissions ?? []) {
      expect(p.id, `permission ${p.id} is not namespaced`).toMatch(/^[a-z-]+:[a-z-]+$/);
      expect(p.label, `permission ${p.id} has no label`).toBeTruthy();
    }
  });

  it("declares every permission it references", () => {
    // A nav entry or route gated on an undeclared permission is invisible to
    // everyone but an admin, silently.
    const declared = new Set((manifest.permissions ?? []).map((p) => p.id));
    const referenced = [
      ...(manifest.nav ?? []).map((n) => n.permission),
      ...(manifest.routes ?? []).map((r) => r.permission),
      ...(manifest.widgets ?? []).map((w) => w.permission),
    ].filter((p): p is string => Boolean(p));

    expect(referenced.length).toBeGreaterThan(0);
    for (const perm of referenced) {
      expect(declared.has(perm), `${perm} is referenced but never declared`).toBe(true);
    }
  });

  it("gates the page and the widget on read, never on nothing", () => {
    // This hub exposes customer names, rates and invoices; an ungated route
    // would show them to every logged-in user of the product.
    for (const route of manifest.routes ?? []) {
      expect(route.permission, `route ${route.pattern} is ungated`).toBe("opensource:read");
    }
    for (const widget of manifest.widgets ?? []) {
      expect(widget.permission, `widget ${widget.id} is ungated`).toBe("opensource:read");
    }
  });

  it("has unique permission ids", () => {
    const ids = (manifest.permissions ?? []).map((p) => p.id);
    expect(new Set(ids).size).toBe(ids.length);
  });
});

describe("nav, routes and widgets", () => {
  it("puts its nav entry on a route it actually serves", () => {
    const patterns = new Set((manifest.routes ?? []).map((r) => r.pattern));
    for (const entry of manifest.nav ?? []) {
      expect(patterns.has(entry.href.split("?")[0]!), `nav ${entry.id} → ${entry.href} is a 404`).toBe(true);
    }
  });

  it("never claims one of the host's base routes", () => {
    // coreFrontendBase injects these; shadowing one would replace a base page.
    const BASE = ["/", "/login", "/users", "/einstellungen", "/wiki"];
    for (const route of manifest.routes ?? []) {
      expect(BASE, `route ${route.pattern} shadows a base page`).not.toContain(route.pattern);
    }
  });

  it("gives every route an absolute pattern and an .astro entrypoint", () => {
    expect((manifest.routes ?? []).length).toBeGreaterThan(0);
    for (const route of manifest.routes ?? []) {
      expect(route.pattern, "route pattern must be absolute").toMatch(/^\//);
      expect(route.entrypoint.endsWith(".astro"), `${route.entrypoint} is not an .astro file`).toBe(true);
    }
  });

  it("points every specifier at its OWN package", () => {
    // A specifier naming another package resolves only when that package
    // happens to be installed — it breaks the moment the set changes.
    const specifiers = [
      ...(manifest.routes ?? []).map((r) => r.entrypoint),
      ...(manifest.widgets ?? []).map((w) => w.island),
      ...(manifest.settings ?? []).map((s) => s.island),
    ];
    expect(specifiers.length).toBeGreaterThan(0);
    for (const spec of specifiers) {
      expect(spec, `${spec} does not live in this package`).toMatch(
        /^@tracht-digital-solutions\/tds-ext-opensource\//,
      );
    }
  });

  it("gives each widget a data endpoint and a size the grid understands", () => {
    for (const w of manifest.widgets ?? []) {
      expect(["sm", "md", "lg"], `widget ${w.id} size`).toContain(w.size);
      expect(w.title, `widget ${w.id} has no title`).toBeTruthy();
    }
  });

  it("points the widget at the endpoint its island actually reads", () => {
    expect(manifest.widgets?.[0]?.dataEndpoint).toBe("/opensource/summary");
  });

  it("namespaces its nav, widget and settings ids under the module", () => {
    // These share one namespace across every composed extension.
    for (const n of manifest.nav ?? []) expect(n.id).toMatch(/^opensource/);
    for (const w of manifest.widgets ?? []) expect(w.id).toMatch(/^opensource/);
    for (const s of manifest.settings ?? []) expect(s.id).toMatch(/^opensource/);
  });

  it("orders nav and widgets deterministically", () => {
    // Without an order the entry floats to an arbitrary slot between builds.
    for (const n of manifest.nav ?? []) expect(typeof n.order, `nav ${n.id}`).toBe("number");
    for (const w of manifest.widgets ?? []) expect(typeof w.order, `widget ${w.id}`).toBe("number");
  });
});

describe("i18n", () => {
  it("ships both languages", () => {
    expect(manifest.i18n?.de).toBeDefined();
    expect(manifest.i18n?.en).toBeDefined();
  });

  it("has identical key sets in de and en", () => {
    // A key present in one language only renders the raw key as UI copy.
    expect(Object.keys(manifest.i18n!.de).sort()).toEqual(Object.keys(manifest.i18n!.en).sort());
  });

  it("has no empty translation", () => {
    for (const [lang, table] of Object.entries(manifest.i18n ?? {})) {
      for (const [key, value] of Object.entries(table)) {
        expect(String(value).trim(), `${lang}.${key} is empty`).not.toBe("");
      }
    }
  });

  it("namespaces its i18n keys so they cannot collide with another extension", () => {
    for (const key of Object.keys(manifest.i18n?.de ?? {})) {
      expect(key, `${key} is not namespaced`).toMatch(/^opensource\./);
    }
  });
});

describe("composition", () => {
  it("composes on its own without throwing", () => {
    expect(() => composeExtensions([manifest])).not.toThrow();
  });

  it("survives being composed next to an unrelated extension", () => {
    const other = {
      id: "unrelated-ext",
      name: "Unrelated",
      version: "1.0.0",
      nav: [{ id: "unrelated-nav", label: "U", href: "/unrelated", order: 99 }],
      routes: [{ pattern: "/unrelated", entrypoint: "@x/y/pages/U.astro" }],
      i18n: { de: {}, en: {} },
    };
    expect(() => composeExtensions([manifest, other as never])).not.toThrow();
  });

  it("is rejected when composed against an extension that reuses its id", () => {
    // Proves the collision guard is real, not assumed.
    expect(() => composeExtensions([manifest, { ...manifest }])).toThrow();
  });

  it("surfaces its route, nav entry and settings panel through the composition", () => {
    const composed = composeExtensions([manifest]);
    expect(composed.routes.map((r) => r.pattern)).toContain("/open-source");
    expect(composed.nav.map((n) => n.id)).toContain("opensource");
    expect(composed.settings.map((s) => s.id)).toContain("opensource");
  });
});
