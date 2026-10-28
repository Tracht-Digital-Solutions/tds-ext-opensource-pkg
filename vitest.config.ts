import { defineConfig } from "vitest/config";

/**
 * Extensions publish their `islands/`, `pages/` and `widgets/` as SOURCE (only
 * `src/` is bundled by tsup), so these tests run against the very files a
 * product build composes.
 *
 *  - `src/index.test.ts` — the manifest: the ids, permissions and entrypoints a
 *    product's `composeExtensions` folds in. A bad specifier here is an ENOENT
 *    in the product build, far from this repo.
 *  - `islands/*.test.tsx` — the React islands in jsdom, per-file via a
 *    `@vitest-environment jsdom` docblock.
 *  - `tests/packaging.test.ts` — that everything the manifest points at is
 *    actually on disk and inside the published `files` allow-list.
 */
export default defineConfig({
  test: {
    include: ["src/**/*.test.{ts,tsx}", "islands/**/*.test.{ts,tsx}", "tests/**/*.test.ts"],
    environment: "node",
    restoreMocks: true,
    unstubGlobals: true,
  },
});
