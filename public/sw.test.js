import { readFileSync } from "node:fs";
import vm from "node:vm";
import { describe, expect, it, vi } from "vitest";

function loadServiceWorker() {
  const listeners = {};
  const context = {
    caches: {
      delete: vi.fn(),
      keys: vi.fn(),
      open: vi.fn(),
    },
    fetch: vi.fn(),
    Request,
    Response,
    self: {
      addEventListener: vi.fn((event, callback) => {
        listeners[event] = callback;
      }),
      clients: {
        claim: vi.fn(),
      },
      location: {
        origin: "https://davvy.test",
      },
      navigator: {},
      registration: {
        showNotification: vi.fn(),
      },
      skipWaiting: vi.fn(),
    },
    URL,
  };

  vm.createContext(context);
  vm.runInContext(readFileSync("public/sw.js", "utf8"), context);

  return { context, listeners };
}

describe("service worker cache policy", () => {
  it("bypasses private, mutating, and cross-origin requests", () => {
    const { context } = loadServiceWorker();

    expect(
      context.shouldBypassRequest(
        new Request("https://davvy.test/api/auth/me"),
      ),
    ).toBe(true);
    expect(
      context.shouldBypassRequest(
        new Request("https://davvy.test/dav/addressbooks/1/personal"),
      ),
    ).toBe(true);
    expect(
      context.shouldBypassRequest(
        new Request("https://davvy.test/", { method: "POST" }),
      ),
    ).toBe(true);
    expect(
      context.shouldBypassRequest(
        new Request("https://cdn.example.test/assets/app.js"),
      ),
    ).toBe(true);
  });

  it("allows app-shell static assets without allowing arbitrary same-origin paths", () => {
    const { context } = loadServiceWorker();

    expect(
      context.shouldBypassRequest(
        new Request("https://davvy.test/manifest.webmanifest"),
      ),
    ).toBe(false);
    expect(
      context.isStaticAssetRequest(
        new Request("https://davvy.test/build/assets/app.js"),
      ),
    ).toBe(true);
    expect(
      context.isStaticAssetRequest(
        new Request("https://davvy.test/images/icons/icon-192.png"),
      ),
    ).toBe(true);
    expect(
      context.isStaticAssetRequest(
        new Request("https://davvy.test/storage/private-export.zip"),
      ),
    ).toBe(false);
  });

  it("honors update activation messages", () => {
    const { context, listeners } = loadServiceWorker();

    listeners.message({ data: { type: "SKIP_WAITING" } });

    expect(context.self.skipWaiting).toHaveBeenCalledTimes(1);
  });
});
