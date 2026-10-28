import { describe, expect, it } from "vitest";

import { cacheMessage, eurosToPresets, presetsToEuros } from "./ProjectsScreen.tsx";

describe("donation presets in euros", () => {
  it("shows stored cents as euros", () => {
    expect(presetsToEuros("500,1000,2550")).toBe("5,00; 10,00; 25,50");
    expect(presetsToEuros("")).toBe("");
  });

  it("reads typed euros back as cents", () => {
    expect(eurosToPresets("5; 10,50; 25 €")).toBe("500,1050,2500");
    expect(eurosToPresets("5\n10")).toBe("500,1000");
  });

  it("refuses anything that is not an amount instead of dropping it", () => {
    expect(eurosToPresets("5; zehn")).toBeNull();
  });
});

describe("what a save reports", () => {
  it("is only green when the site confirmed the rebuild", () => {
    expect(cacheMessage("refreshed").tone).toBe("success");
    // Unpaired is the normal state of a new project, not a failure.
    expect(cacheMessage("not_configured").tone).toBe("info");
    expect(cacheMessage("failed").tone).toBe("warning");
    expect(cacheMessage(undefined).tone).toBe("warning");
  });
});
