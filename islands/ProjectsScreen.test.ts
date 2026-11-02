import { describe, expect, it } from "vitest";

import { cacheMessage, eurosToPresets, fieldsFromRelease, normalizeVersion, presetsToEuros } from "./ProjectsScreen.tsx";

describe("picking a version", () => {
  const releases = [{ version: "0.4.0-alpha", date: "2026-11-01", url: "https://github.com/x/y/releases/tag/v0.4.0-alpha", body: null, prerelease: true }];

  it("treats v1.2.0 and 1.2.0 as the same", () => {
    expect(normalizeVersion("v0.4.0-alpha")).toBe("0.4.0-alpha");
    expect(normalizeVersion(null)).toBe("");
  });

  it("fills date and download from GitHub's list, and leaves a typed version alone", () => {
    expect(fieldsFromRelease("v0.4.0-alpha", releases)).toEqual({ version_date: "2026-11-01", download_url: releases[0]!.url });
    expect(fieldsFromRelease("9.9.9", releases)).toEqual({});
  });
});

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
