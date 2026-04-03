import React from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AddressBookMilestoneControls from "./AddressBookMilestoneControls";

function TestIcon({ className = "" }) {
  return <svg className={className} aria-hidden="true" />;
}

function createItem(overrides = {}) {
  return {
    id: 42,
    display_name: "Friends",
    milestone_calendars: {
      birthdays: {
        enabled: false,
        custom_name: "",
        default_name: "Friends Birthdays",
        calendar_id: null,
        calendar_name: "Friends Birthdays",
      },
      anniversaries: {
        enabled: true,
        custom_name: "",
        default_name: "Friends Anniversaries",
        calendar_id: 84,
        calendar_name: "Friends Anniversaries",
      },
    },
    ...overrides,
  };
}

function renderControls({ item = createItem(), onSave, onDeleteCalendar } = {}) {
  const saveHandler = onSave ?? vi.fn().mockResolvedValue(undefined);
  const deleteHandler = onDeleteCalendar ?? vi.fn().mockResolvedValue(undefined);

  render(
    <AddressBookMilestoneControls
      item={item}
      onSave={saveHandler}
      onDeleteCalendar={deleteHandler}
      ChevronRightIcon={TestIcon}
      ResetIcon={TestIcon}
      PencilIcon={TestIcon}
      CheckIcon={TestIcon}
      TimesIcon={TestIcon}
    />,
  );

  return {
    user: userEvent.setup(),
    onSave: saveHandler,
    onDeleteCalendar: deleteHandler,
  };
}

describe("AddressBookMilestoneControls", () => {
  it("saves birthday enabled toggle changes", async () => {
    const { user, onSave } = renderControls();

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );
    await user.click(screen.getByRole("checkbox", { name: "Birthdays" }));

    await waitFor(() =>
      expect(onSave).toHaveBeenCalledWith(42, {
        birthdays_enabled: true,
      }),
    );
  });

  it("saves renamed birthday calendar names", async () => {
    const { user, onSave } = renderControls();

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );
    await user.click(
      screen.getByRole("button", { name: "Rename Birthdays calendar" }),
    );
    await user.type(screen.getByPlaceholderText("Friends Birthdays"), "Family");
    await user.click(
      screen.getByRole("button", { name: "Save Birthdays calendar name" }),
    );

    await waitFor(() =>
      expect(onSave).toHaveBeenCalledWith(42, {
        birthday_calendar_name: "Family",
      }),
    );
  });

  it("resets custom birthday calendar names to default", async () => {
    const item = createItem({
      milestone_calendars: {
        birthdays: {
          enabled: true,
          custom_name: "Legacy Birthdays",
          default_name: "Friends Birthdays",
        },
        anniversaries: {
          enabled: false,
          custom_name: "",
          default_name: "Friends Anniversaries",
        },
      },
    });
    const { user, onSave } = renderControls({ item });

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );
    await user.click(
      screen.getByRole("button", {
        name: "Reset Birthdays calendar name to default",
      }),
    );

    await waitFor(() =>
      expect(onSave).toHaveBeenCalledWith(42, {
        birthday_calendar_name: null,
      }),
    );
  });

  it("deletes generated milestone calendar when disabling and confirming", async () => {
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    const { user, onSave, onDeleteCalendar } = renderControls();

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );
    await user.click(screen.getByRole("checkbox", { name: "Anniversaries" }));

    await waitFor(() =>
      expect(onDeleteCalendar).toHaveBeenCalledWith({
        id: 84,
        display_name: "Friends Anniversaries",
      }),
    );
    expect(onSave).not.toHaveBeenCalledWith(42, {
      anniversaries_enabled: false,
    });
    expect(confirmSpy).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it("keeps generated milestone calendar when disabling and cancelling deletion", async () => {
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(false);
    const { user, onSave, onDeleteCalendar } = renderControls();

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );
    await user.click(screen.getByRole("checkbox", { name: "Anniversaries" }));

    await waitFor(() =>
      expect(onSave).toHaveBeenCalledWith(42, {
        anniversaries_enabled: false,
      }),
    );
    expect(onDeleteCalendar).not.toHaveBeenCalled();
    expect(confirmSpy).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it("renders milestone sync helper copy when expanded", async () => {
    const { user } = renderControls();

    await user.click(
      screen.getByRole("button", { name: "Expand milestone calendars" }),
    );

    expect(
      screen.getByText(
        "When enabled, Davvy keeps generated milestone calendar events in sync with contact dates.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Turning off sync does not delete calendars unless you choose Delete.",
      ),
    ).toBeInTheDocument();
  });
});
