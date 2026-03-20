import { describe, expect, it } from "vitest";
import {
  buildLocaleOptions,
  localeDirection,
  localeDisplayName,
} from "./locale";

describe("locale display helpers", () => {
  it("returns stable display names for built-in locales", () => {
    expect(localeDisplayName("de")).toBe("Deutsch");
    expect(localeDisplayName("en")).toBe("English");
    expect(localeDisplayName("es")).toBe("Español");
    expect(localeDisplayName("fr")).toBe("Français");
    expect(localeDisplayName("it")).toBe("Italiano");
    expect(localeDisplayName("pt")).toBe("Português");
    expect(localeDisplayName("zh")).toBe("中文");
  });

  it("classifies rtl locales for future UI support", () => {
    expect(localeDirection("ar")).toBe("rtl");
    expect(localeDirection("he-IL")).toBe("rtl");
    expect(localeDirection("en-US")).toBe("ltr");
  });

  it("builds deduplicated locale options with metadata", () => {
    const options = buildLocaleOptions([
      "de",
      "en",
      "es",
      "fr",
      "it",
      "pt",
      "zh",
      "en",
    ]);

    expect(options).toEqual([
      { value: "de", label: "Deutsch", dir: "ltr" },
      { value: "en", label: "English", dir: "ltr" },
      { value: "es", label: "Español", dir: "ltr" },
      { value: "fr", label: "Français", dir: "ltr" },
      { value: "it", label: "Italiano", dir: "ltr" },
      { value: "pt", label: "Português", dir: "ltr" },
      { value: "zh", label: "中文", dir: "ltr" },
    ]);
  });
});
