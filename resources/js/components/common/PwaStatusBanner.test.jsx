import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import PwaStatusBanner from "./PwaStatusBanner";

describe("PwaStatusBanner", () => {
  it("shows offline status", () => {
    render(<PwaStatusBanner isOnline={false} />);

    expect(screen.getByRole("status")).toHaveTextContent(
      "You are offline. Live data and DAV actions will resume after reconnecting.",
    );
  });

  it("uses a fixed bottom snackbar wrapper", () => {
    render(<PwaStatusBanner isOnline={false} />);

    expect(screen.getByRole("status").parentElement).toHaveClass(
      "pwa-status-viewport",
    );
    expect(screen.getByRole("status")).toHaveClass("pwa-status-card");
  });

  it("shows update action", async () => {
    const user = userEvent.setup();
    const onActivateUpdate = vi.fn();

    render(
      <PwaStatusBanner
        isOnline
        updateAvailable
        onActivateUpdate={onActivateUpdate}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Reload" }));

    expect(screen.getByRole("status")).toHaveTextContent(
      "A new version of Davvy is ready.",
    );
    expect(onActivateUpdate).toHaveBeenCalledTimes(1);
  });

  it("shows dismissible offline-ready status", async () => {
    const user = userEvent.setup();
    const onDismissOfflineReady = vi.fn();

    render(
      <PwaStatusBanner
        isOnline
        offlineReady
        onDismissOfflineReady={onDismissOfflineReady}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Dismiss" }));

    expect(screen.getByRole("status")).toHaveTextContent(
      "Davvy is ready for offline launch.",
    );
    expect(onDismissOfflineReady).toHaveBeenCalledTimes(1);
  });
});
