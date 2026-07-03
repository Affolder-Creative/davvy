import { afterEach, describe, expect, it, vi } from "vitest";
import {
  currentPushSubscription,
  serializePushSubscription,
  setDavvyAppBadge,
} from "./webPush";

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

describe("webPush helpers", () => {
  it("serializes browser push subscriptions for the API", () => {
    const subscription = {
      toJSON: () => ({
        endpoint: "https://push.example.test/abc",
        keys: {
          p256dh: "public-key",
          auth: "auth-token",
        },
      }),
    };

    expect(serializePushSubscription(subscription)).toEqual({
      endpoint: "https://push.example.test/abc",
      keys: {
        p256dh: "public-key",
        auth: "auth-token",
      },
      content_encoding: "aes128gcm",
    });
  });

  it("uses Badging API when available and ignores unsupported browsers", async () => {
    const setAppBadge = vi.fn().mockResolvedValue(undefined);
    const clearAppBadge = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "setAppBadge", {
      configurable: true,
      value: setAppBadge,
    });
    Object.defineProperty(navigator, "clearAppBadge", {
      configurable: true,
      value: clearAppBadge,
    });

    await setDavvyAppBadge(4);
    await setDavvyAppBadge(0);

    expect(setAppBadge).toHaveBeenCalledWith(4);
    expect(clearAppBadge).toHaveBeenCalledTimes(1);
  });

  it("uses the shared service worker registration for existing subscriptions", async () => {
    const subscription = { endpoint: "https://push.example.test/sub" };
    const registration = {
      addEventListener: vi.fn(),
      pushManager: {
        getSubscription: vi.fn().mockResolvedValue(subscription),
      },
      waiting: null,
    };
    const serviceWorker = {
      register: vi.fn().mockResolvedValue(registration),
    };
    mockServiceWorker(serviceWorker);

    await expect(currentPushSubscription()).resolves.toBe(subscription);

    expect(serviceWorker.register).toHaveBeenCalledWith("/sw.js");
    expect(registration.pushManager.getSubscription).toHaveBeenCalledTimes(1);
  });

  it("returns null for existing subscriptions when PushManager is unavailable", async () => {
    const registration = {
      addEventListener: vi.fn(),
      waiting: null,
    };
    mockServiceWorker({
      register: vi.fn().mockResolvedValue(registration),
    });

    await expect(currentPushSubscription()).resolves.toBeNull();
  });
});
