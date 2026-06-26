import React from "react";
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import FullPageState from "./FullPageState";

describe("FullPageState", () => {
  it("renders default branded full-page layout", () => {
    const { container } = render(<FullPageState label="Loading..." />);

    const status = screen.getByRole("status");
    expect(status).toHaveClass("app-loading-screen");
    expect(status).toHaveAttribute("aria-live", "polite");
    expect(screen.getByText("Davvy")).toHaveClass("app-loading-title");
    const label = screen.getByText("Loading...");
    expect(label).toHaveClass("app-loading-label");

    expect(container.querySelector('img[src="/davvy.png"]')).toHaveClass(
      "app-loading-icon",
      "app-loading-icon-light",
    );
    expect(container.querySelector('img[src="/davvy_dark.png"]')).toHaveClass(
      "app-loading-icon",
      "app-loading-icon-dark",
    );
  });

  it("renders compact layout", () => {
    render(<FullPageState label="Loading compact" compact />);

    const label = screen.getByText("Loading compact");
    expect(label).toHaveClass("mt-4", "text-sm", "font-semibold", "text-app-muted");
  });
});
