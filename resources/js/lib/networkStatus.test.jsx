import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { isNavigatorOnline, useNetworkStatus } from "./networkStatus";

const originalOnLine = Object.getOwnPropertyDescriptor(navigator, "onLine");

afterEach(() => {
  if (originalOnLine) {
    Object.defineProperty(navigator, "onLine", originalOnLine);
  } else {
    delete navigator.onLine;
  }
});

function setNavigatorOnline(value) {
  Object.defineProperty(navigator, "onLine", {
    configurable: true,
    value,
  });
}

describe("network status helpers", () => {
  it("reads navigator online state", () => {
    setNavigatorOnline(false);

    expect(isNavigatorOnline()).toBe(false);
  });

  it("updates when the browser goes online and offline", () => {
    setNavigatorOnline(true);
    const { result } = renderHook(() => useNetworkStatus());

    expect(result.current.isOnline).toBe(true);

    act(() => {
      setNavigatorOnline(false);
      window.dispatchEvent(new Event("offline"));
    });

    expect(result.current.isOnline).toBe(false);

    act(() => {
      setNavigatorOnline(true);
      window.dispatchEvent(new Event("online"));
    });

    expect(result.current.isOnline).toBe(true);
  });
});
