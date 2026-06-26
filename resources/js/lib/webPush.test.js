import { describe, expect, it, vi } from "vitest";
import { serializePushSubscription, setDavvyAppBadge } from "./webPush";

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
});
