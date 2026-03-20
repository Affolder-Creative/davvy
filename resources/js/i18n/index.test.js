import { beforeEach, describe, expect, it } from "vitest";
import i18n, { setI18nLocale } from "./index";

describe("i18n locale synchronization", () => {
  beforeEach(async () => {
    window.localStorage.clear();
    await i18n.changeLanguage("en");
    document.documentElement.lang = "en";
    document.title = "";
  });

  it("normalizes and applies locale to i18n, document, and storage", async () => {
    setI18nLocale("es-MX", {
      supportedLocales: ["en", "es"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("es");
    expect(document.documentElement.lang).toBe("es");
    expect(document.title).toBe("Davvy - Administrador de CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("es");
  });

  it("supports french locale when it is included in supported locales", async () => {
    setI18nLocale("fr-CA", {
      supportedLocales: ["en", "es", "fr"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("fr");
    expect(document.documentElement.lang).toBe("fr");
    expect(document.title).toBe("Davvy - Gestionnaire CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("fr");
  });

  it("supports german locale when it is included in supported locales", async () => {
    setI18nLocale("de-DE", {
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("de");
    expect(document.documentElement.lang).toBe("de");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV-Manager");
    expect(window.localStorage.getItem("davvy.locale")).toBe("de");
  });

  it("supports italian locale when it is included in supported locales", async () => {
    setI18nLocale("it-IT", {
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("it");
    expect(document.documentElement.lang).toBe("it");
    expect(document.title).toBe("Davvy - Gestore CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("it");
  });

  it("supports japanese locale when it is included in supported locales", async () => {
    setI18nLocale("ja-JP", {
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("ja");
    expect(document.documentElement.lang).toBe("ja");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV マネージャー");
    expect(window.localStorage.getItem("davvy.locale")).toBe("ja");
  });

  it("supports portuguese locale when it is included in supported locales", async () => {
    setI18nLocale("pt-BR", {
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("pt");
    expect(document.documentElement.lang).toBe("pt");
    expect(document.title).toBe("Davvy - Gerente CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("pt");
  });

  it("supports chinese locale when it is included in supported locales", async () => {
    setI18nLocale("zh-CN", {
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("zh");
    expect(document.documentElement.lang).toBe("zh");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV 管理器");
    expect(window.localStorage.getItem("davvy.locale")).toBe("zh");
  });

  it("falls back to configured fallback locale", async () => {
    setI18nLocale("fr-FR", {
      supportedLocales: ["en", "es"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("en");
    expect(document.documentElement.lang).toBe("en");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV Manager");
    expect(window.localStorage.getItem("davvy.locale")).toBe("en");
  });
});
