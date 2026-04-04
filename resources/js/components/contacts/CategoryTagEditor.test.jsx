import React, { useState } from "react";
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CategoryTagEditor from "./CategoryTagEditor";

function Harness({
  initialCategories = [],
  suggestions = [],
}) {
  const [categories, setCategories] = useState(initialCategories);

  return (
    <>
      <CategoryTagEditor
        categories={categories}
        onChange={setCategories}
        suggestions={suggestions}
      />
      <pre data-testid="categories-state">{JSON.stringify(categories)}</pre>
    </>
  );
}

describe("CategoryTagEditor", () => {
  it("adds categories and dedupes case-insensitively", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    const input = screen.getByPlaceholderText("Add category");
    await user.type(input, "Family{enter}");
    expect(screen.getByTestId("categories-state")).toHaveTextContent(
      '["Family"]',
    );

    await user.type(input, "family,");
    expect(screen.getByTestId("categories-state")).toHaveTextContent(
      '["Family"]',
    );
  });

  it("shows suggestions and allows selecting them", async () => {
    const user = userEvent.setup();
    render(<Harness suggestions={["Friends", "Vendors"]} />);

    const input = screen.getByPlaceholderText("Add category");
    await user.click(input);
    await user.type(input, "ven");

    expect(
      document.getElementById("contact-categories-combobox-list"),
    ).toHaveClass("z-30");
    await user.click(screen.getByRole("button", { name: "Vendors" }));

    expect(screen.getByTestId("categories-state")).toHaveTextContent(
      '["Vendors"]',
    );
  });

  it("removes tags by chip button and backspace", async () => {
    const user = userEvent.setup();
    render(<Harness initialCategories={["Family", "Work"]} />);

    await user.click(screen.getByRole("button", { name: "Remove Family" }));
    expect(screen.getByTestId("categories-state")).toHaveTextContent(
      '["Work"]',
    );

    const input = screen.getByPlaceholderText("Add category");
    await user.click(input);
    await user.keyboard("{Backspace}");
    expect(screen.getByTestId("categories-state")).toHaveTextContent("[]");
  });
});
