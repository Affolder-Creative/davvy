import React from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import ContactEditorPhotoSection from "./ContactEditorPhotoSection";

describe("ContactEditorPhotoSection cropper integration", () => {
  beforeEach(() => {
    // jsdom does not decode images or lay out the cropper. Keep the real
    // cropper and provide only the browser measurements it needs.
    vi.spyOn(HTMLImageElement.prototype, "naturalWidth", "get").mockReturnValue(1200);
    vi.spyOn(HTMLImageElement.prototype, "naturalHeight", "get").mockReturnValue(1200);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
      x: 0, y: 0, top: 0, left: 0, right: 400, bottom: 400,
      width: 400, height: 400,
    });
    vi.stubGlobal("Image", class {
      naturalWidth = 1200;
      naturalHeight = 1200;
      set src(value) {
        queueMicrotask(() => this.onload());
      }
    });
    vi.stubGlobal("URL", class extends URL {
      static createObjectURL = vi.fn(() => "blob:contact-photo");
      static revokeObjectURL = vi.fn();
    });
    vi.spyOn(HTMLCanvasElement.prototype, "getContext").mockReturnValue({
      drawImage: vi.fn(),
    });
    vi.spyOn(HTMLCanvasElement.prototype, "toBlob").mockImplementation((callback) => {
      callback(new Blob(["preview"], { type: "image/jpeg" }));
    });
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it("stages pixel coordinates from the real cropper after zooming", async () => {
    const onStagePhotoUpload = vi.fn().mockResolvedValue(undefined);
    const { container } = render(
      <ContactEditorPhotoSection
        constraints={{ min_crop_size: 600 }}
        onStagePhotoUpload={onStagePhotoUpload}
      />,
    );
    const file = new File(["photo"], "photo.jpg", { type: "image/jpeg" });
    fireEvent.change(container.querySelector('input[type="file"]'), {
      target: { files: [file] },
    });

    const cropImage = await screen.findByAltText("");
    fireEvent.load(cropImage);
    fireEvent.change(screen.getByRole("slider"), { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: /use.*crop/i }));

    await waitFor(() => expect(onStagePhotoUpload).toHaveBeenCalledWith({
      file,
      crop: { x: 300, y: 300, width: 600, height: 600 },
    }));
    await waitFor(() => expect(screen.queryByRole("slider")).not.toBeInTheDocument());
  });
});
