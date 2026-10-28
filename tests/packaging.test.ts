import { existsSync, readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import manifest from "../src/index";

/**
 * The manifest names its pages/widgets/islands by PACKAGE SPECIFIER. Those are
 * resolved by the product build, against the published tarball — so a specifier
 * can be perfectly valid here and still ENOENT in the product if:
 *
 *   - the file does not exist,
 *   - `exports` has no subpath pattern that maps it,
 *   - or its directory is missing from the `files` allow-list, so it never
 *     ships at all.
 *
 * All three fail in someone else's repo, during a release build. These tests
 * move that failure here.
 */

const root = new URL("../", import.meta.url);
const pkg = JSON.parse(readFileSync(new URL("package.json", root), "utf8")) as {
  name: string;
  version: string;
  files: string[];
  exports: Record<string, unknown>;
  scripts: Record<string, string>;
  peerDependencies?: Record<string, string>;
  dependencies?: Record<string, string>;
};

/** Every specifier the manifest asks the product build to resolve. */
const specifiers = [
  ...(manifest.routes ?? []).map((r) => r.entrypoint),
  ...(manifest.widgets ?? []).map((w) => w.island),
  ...(manifest.settings ?? []).map((s) => s.island),
];

/** The minor line the admin product caret-pins this package at (`^0.1.1`). */
const PINNED_MINOR_LINE = "0.1";

/** `@scope/name/pages/Index.astro` → `pages/Index.astro` */
const subpath = (spec: string) => spec.slice(pkg.name.length + 1);

describe("published surface", () => {
  it("resolves every manifest specifier to a file on disk", () => {
    expect(specifiers.length).toBeGreaterThan(0);
    for (const spec of specifiers) {
      const file = new URL(subpath(spec), root);
      expect(existsSync(file), `${spec} → ${subpath(spec)} does not exist`).toBe(true);
    }
  });

  it("has an exports entry covering every manifest specifier", () => {
    const patterns = Object.keys(pkg.exports).filter((k) => k.endsWith("/*"));
    for (const spec of specifiers) {
      const dir = `./${subpath(spec).split("/")[0]}/*`;
      expect(patterns, `${spec} is not reachable through "exports"`).toContain(dir);
    }
  });

  it("ships every referenced directory in the files allow-list", () => {
    // Missing here = the directory is absent from the tarball, and the product
    // build fails on install, not on build.
    for (const spec of specifiers) {
      const dir = subpath(spec).split("/")[0]!;
      expect(pkg.files, `"${dir}" is referenced but not published`).toContain(dir);
    }
  });

  it("publishes the built entrypoint the manifest import resolves to", () => {
    expect(pkg.files).toContain("dist");
    expect(pkg.exports["."]).toMatchObject({
      types: expect.stringContaining("dist/"),
      import: expect.stringContaining("dist/"),
    });
  });

  it("ships the PHP module alongside the frontend manifest", () => {
    // The extension is dual — a product installs the npm side, the API kernel
    // Composer-requires the same repo. Dropping `php` from `files` breaks the
    // backend half without touching the frontend.
    expect(pkg.files).toContain("php");
    expect(existsSync(new URL("php", root))).toBe(true);
  });

  it("publishes the islands the .astro shells hydrate", () => {
    // The widget/settings shells are .astro files that import a sibling .tsx
    // island; the manifest never names the .tsx, so only the `files` entry
    // keeps it in the tarball.
    expect(pkg.files).toContain("islands");
    for (const island of ["WidgetBody.tsx", "ProjectsScreen.tsx", "ConnectionSettings.tsx"]) {
      expect(existsSync(new URL(`islands/${island}`, root)), `islands/${island} is missing`).toBe(true);
    }
  });
});

describe("dependency hygiene", () => {
  it("depends on the contract, not on a sibling path", () => {
    const contract = "@tracht-digital-solutions/tds-frontend-contract";
    const range = pkg.dependencies?.[contract] ?? pkg.peerDependencies?.[contract];
    expect(range, "the contract must be a declared dependency").toBeDefined();
    expect(range, "a file:/link: range never resolves for a consumer").not.toMatch(/^(file:|link:)/);
  });

  it("stays inside the minor line the products caret-pin", () => {
    // tds-admin-frontend depends on this package with a CARET (`^0.1.1`). Under
    // 0.x a caret means `>=0.1.1 <0.2.0`, so bumping the MINOR here silently
    // stops the product picking it up until its range is widened by hand.
    //
    // NOTE: the root CLAUDE.md says extensions stay in `0.1.x`. That is not
    // universal — support-tickets is pinned at 0.7.x and contact-tickets at
    // 0.2.x. What matters is that an extension never leaves the line its
    // consumers pin.
    expect(pkg.version.startsWith(`${PINNED_MINOR_LINE}.`)).toBe(true);
  });

  it("exposes the scripts CI runs", () => {
    for (const script of ["build", "type-check", "test:run"]) {
      expect(pkg.scripts[script], `missing npm script: ${script}`).toBeDefined();
    }
  });
});
