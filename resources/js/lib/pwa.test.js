import { afterEach, describe, expect, it, vi } from "vitest";
import {
  activateWaitingServiceWorker,
  isServiceWorkerSupported,
  registerDavvyServiceWorker,
} from "./pwa";

const originalServiceWorker = Object.getOwnPropertyDescriptor(
  navigator,
  "serviceWorker",
);

afterEach(() => {
  if (originalServiceWorker) {
    Object.defineProperty(navigator, "serviceWorker", originalServiceWorker);
  } else {
    delete navigator.serviceWorker;
  }
});

function mockServiceWorker(serviceWorker) {
  Object.defineProperty(navigator, "serviceWorker", {
    configurable: true,
    value: serviceWorker,
  });
}

describe("pwa helpers", () => {
  it("skips registration when service workers are unavailable", async () => {
    expect(isServiceWorkerSupported()).toBe(false);
    await expect(registerDavvyServiceWorker()).resolves.toBeNull();
  });

  it("registers the Davvy service worker", async () => {
    const registration = {
      addEventListener: vi.fn(),
      waiting: null,
    };
    const serviceWorker = {
      register: vi.fn().mockResolvedValue(registration),
    };
    mockServiceWorker(serviceWorker);

    await expect(registerDavvyServiceWorker()).resolves.toBe(registration);

    expect(serviceWorker.register).toHaveBeenCalledWith("/sw.js");
  });

  it("reports an already waiting worker as an available update", async () => {
    const registration = {
      addEventListener: vi.fn(),
      waiting: { postMessage: vi.fn() },
    };
    const onUpdateAvailable = vi.fn();
    mockServiceWorker({
      register: vi.fn().mockResolvedValue(registration),
    });

    await registerDavvyServiceWorker({ onUpdateAvailable });

    expect(onUpdateAvailable).toHaveBeenCalledWith(registration);
  });

  it("reports updatefound installed workers as updates when controlled", async () => {
    let onUpdateFound;
    let onStateChange;
    const installingWorker = {
      state: "installing",
      addEventListener: vi.fn((event, callback) => {
        if (event === "statechange") {
          onStateChange = callback;
        }
      }),
    };
    const registration = {
      addEventListener: vi.fn((event, callback) => {
        if (event === "updatefound") {
          onUpdateFound = callback;
        }
      }),
      get installing() {
        return installingWorker;
      },
      waiting: null,
    };
    const onUpdateAvailable = vi.fn();
    mockServiceWorker({
      controller: {},
      register: vi.fn().mockResolvedValue(registration),
    });

    await registerDavvyServiceWorker({ onUpdateAvailable });
    onUpdateFound();
    installingWorker.state = "installed";
    onStateChange();

    expect(onUpdateAvailable).toHaveBeenCalledWith(registration);
  });

  it("reports first install completion as offline ready", async () => {
    let onUpdateFound;
    let onStateChange;
    const installingWorker = {
      state: "installing",
      addEventListener: vi.fn((event, callback) => {
        if (event === "statechange") {
          onStateChange = callback;
        }
      }),
    };
    const registration = {
      addEventListener: vi.fn((event, callback) => {
        if (event === "updatefound") {
          onUpdateFound = callback;
        }
      }),
      get installing() {
        return installingWorker;
      },
      waiting: null,
    };
    const onOfflineReady = vi.fn();
    mockServiceWorker({
      controller: null,
      register: vi.fn().mockResolvedValue(registration),
    });

    await registerDavvyServiceWorker({ onOfflineReady });
    onUpdateFound();
    installingWorker.state = "installed";
    onStateChange();

    expect(onOfflineReady).toHaveBeenCalledWith(registration);
  });

  it("activates a waiting service worker with SKIP_WAITING", () => {
    const postMessage = vi.fn();

    expect(
      activateWaitingServiceWorker({ waiting: { postMessage } }),
    ).toBe(true);
    expect(postMessage).toHaveBeenCalledWith({ type: "SKIP_WAITING" });
    expect(activateWaitingServiceWorker(null)).toBe(false);
  });
});
