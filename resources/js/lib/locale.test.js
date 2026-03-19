import { describe, expect, it } from "vitest";
import {
  buildLocaleOptions,
  localeDirection,
  localeDisplayName,
} from "./locale";

describe("locale display helpers", () => {
  it("returns stable display names for built-in locales", () => {
    expect(localeDisplayName("en")).toBe("English");
    expect(localeDisplayName("es")).toBe("Español");
  });

  it("classifies rtl locales for future UI support", () => {
    expect(localeDirection("ar")).toBe("rtl");
    expect(localeDirection("he-IL")).toBe("rtl");
    expect(localeDirection("en-US")).toBe("ltr");
  });

  it("builds deduplicated locale options with metadata", () => {
    const options = buildLocaleOptions(["en", "es", "en"]);

    expect(options).toEqual([
      { value: "en", label: "English", dir: "ltr" },
      { value: "es", label: "Español", dir: "ltr" },
    ]);
  });
});
