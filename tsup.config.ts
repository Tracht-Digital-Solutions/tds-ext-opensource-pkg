import { defineConfig } from "tsup";

export default defineConfig({
  entry: { index: "src/index.ts" },
  format: ["esm", "cjs"],
  dts: true,
  splitting: false,
  sourcemap: true,
  clean: true,
  // The manifest resolves defineExtension from the contract at the host; the
  // .astro/.tsx pages + islands are consumed as raw source (see exports).
  external: ["@tracht-digital-solutions/tds-frontend-contract"],
});
