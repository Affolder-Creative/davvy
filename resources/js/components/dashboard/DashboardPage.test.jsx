import React from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import DashboardPage from "./DashboardPage";

function AppShellStub({ children }) {
  return <div>{children}</div>;
}

function FullPageStateStub({ label }) {
  return <div>{label}</div>;
}

function InfoCardStub({ title, value }) {
  return (
    <article>
      <h3>{title}</h3>
      <p>{value}</p>
    </article>
  );
}

function PermissionBadgeStub({ permission }) {
  return <span>{permission}</span>;
}

function ResourcePanelStub({
  title,
  onToggle,
  onDelete,
  items = [],
  renderOwnedItemExtra = null,
}) {
  return (
    <section>
      <h4>{title}</h4>
      <button
        type="button"
        onClick={() => onToggle(1, true, `${title} Resource`)}
      >
        Toggle {title}
      </button>
      <button
        type="button"
        onClick={() => onDelete({ id: 2, display_name: `${title} Resource` })}
      >
        Delete {title}
      </button>
      {renderOwnedItemExtra && items[0] ? renderOwnedItemExtra(items[0]) : null}
    </section>
  );
}

function AddressBookMilestoneControlsStub({ onDeleteCalendar }) {
  return (
    <button
      type="button"
      onClick={() => onDeleteCalendar({ id: 99, display_name: "Birthdays" })}
    >
      Delete Milestone Calendar
    </button>
  );
}

function DashboardOverviewCardsStub() {
  return <section>Overview Cards</section>;
}

function DashboardSharingPanelStub() {
  return <section>Sharing Panel</section>;
}

function DashboardAppleCompatPanelStub({
  setAppleCompatForm,
  appleCompatNotice,
  savingAppleCompat,
  onSaveAppleCompat,
}) {
  return (
    <section>
      <button
        type="button"
        onClick={() =>
          setAppleCompatForm({
            enabled: true,
            source_ids: [9],
          })
        }
      >
        Enable Apple Compat
      </button>
      <button
        type="button"
        onClick={() => onSaveAppleCompat({ preventDefault() {} })}
      >
        {savingAppleCompat ? "Saving..." : "Save Apple Compat"}
      </button>
      {appleCompatNotice ? (
        <p data-testid="apple-compat-notice">{appleCompatNotice}</p>
      ) : null}
    </section>
  );
}

function DashboardPrivateWorkingSetPanelStub({
  setPrivateWorkingSetForm,
  privateWorkingSetIsDirty,
  onSavePrivateWorkingSet,
  onPromotePrivateCard,
  onDismissSuggestedPromotion,
  privateWorkingSetNotice,
  promotingPrivateCardId,
  dismissingSuggestionLinkId,
}) {
  return (
    <section>
      <p data-testid="private-working-set-dirty">
        {privateWorkingSetIsDirty ? "dirty" : "clean"}
      </p>
      <button
        type="button"
        onClick={() =>
          setPrivateWorkingSetForm((previous) => ({
            ...previous,
            hide_shared: !previous.hide_shared,
          }))
        }
      >
        Mutate PWS Form
      </button>
      <button
        type="button"
        onClick={() => onSavePrivateWorkingSet({ preventDefault() {} })}
      >
        Save PWS
      </button>
      <button type="button" onClick={() => onPromotePrivateCard(33)}>
        Promote Private Card
      </button>
      <button type="button" onClick={() => onDismissSuggestedPromotion(44)}>
        Dismiss Suggested Promotion
      </button>
      {promotingPrivateCardId === 33 ? <p>Promoting...</p> : null}
      {dismissingSuggestionLinkId === 44 ? <p>Dismissing...</p> : null}
      {privateWorkingSetNotice ? (
        <p data-testid="private-working-set-notice">{privateWorkingSetNotice}</p>
      ) : null}
    </section>
  );
}

function baseDashboardPayload(overrides = {}) {
  return {
    owned: { calendars: [], address_books: [] },
    shared: { calendars: [], address_books: [] },
    sharing: { can_manage: true, targets: [], outgoing: [] },
    apple_compat: {
      enabled: false,
      target_address_book_id: 77,
      target_address_book_uri: "contacts",
      target_display_name: "Contacts",
      selected_source_ids: [],
      source_options: [],
    },
    ...overrides,
  };
}

function buildProps(overrides = {}) {
  return {
    auth: {
      user: {
        id: 10,
        role: "admin",
      },
    },
    theme: {},
    api: {
      get: vi
        .fn()
        .mockResolvedValue({ data: baseDashboardPayload() }),
      patch: vi.fn().mockResolvedValue({}),
      post: vi.fn().mockResolvedValue({}),
      delete: vi.fn().mockResolvedValue({}),
    },
    extractError: vi.fn((_, fallback) => fallback),
    downloadExport: vi.fn().mockResolvedValue(undefined),
    fileStem: vi.fn((value, fallback) => value || fallback),
    AppShell: AppShellStub,
    FullPageState: FullPageStateStub,
    InfoCard: InfoCardStub,
    PermissionBadge: PermissionBadgeStub,
    ResourcePanel: ResourcePanelStub,
    AddressBookMilestoneControls: AddressBookMilestoneControlsStub,
    DashboardOverviewCards: DashboardOverviewCardsStub,
    DashboardSharingPanel: DashboardSharingPanelStub,
    DashboardAppleCompatPanel: DashboardAppleCompatPanelStub,
    DashboardPrivateWorkingSetPanel: DashboardPrivateWorkingSetPanelStub,
    ...overrides,
  };
}

describe("DashboardPage", () => {
  it("loads dashboard data and renders primary sections", async () => {
    const props = buildProps();
    render(<DashboardPage {...props} />);

    expect(screen.getByText("Loading resources...")).toBeInTheDocument();

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/dashboard"),
    );

    expect(screen.getByText("Overview Cards")).toBeInTheDocument();
    expect(screen.getByText("Your Calendars")).toBeInTheDocument();
    expect(screen.getByText("Your Address Books")).toBeInTheDocument();
    expect(screen.getByText("Sharing Panel")).toBeInTheDocument();
  });

  it("toggles sharable status and shows share status notice", async () => {
    const user = userEvent.setup();
    const props = buildProps();
    render(<DashboardPage {...props} />);

    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole("button", { name: "Toggle Your Calendars" }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith("/api/calendars/1", {
        is_sharable: true,
      }),
    );
    expect(
      screen.getByText("Your Calendars Resource is now shared."),
    ).toBeInTheDocument();
  });

  it("saves apple compatibility settings with current form", async () => {
    const user = userEvent.setup();
    const props = buildProps();
    render(<DashboardPage {...props} />);

    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole("button", { name: "Enable Apple Compat" }));
    await user.click(screen.getByRole("button", { name: "Save Apple Compat" }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/address-books/apple-compat",
        {
          enabled: true,
          source_ids: [9],
        },
      ),
    );
    expect(screen.getByTestId("apple-compat-notice")).toHaveTextContent(
      "Apple compatibility settings saved.",
    );
  });

  it("deletes a resource only after confirmation", async () => {
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    const props = buildProps();
    render(<DashboardPage {...props} />);

    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole("button", { name: "Delete Your Calendars" }));

    await waitFor(() =>
      expect(props.api.delete).toHaveBeenCalledWith("/api/calendars/2"),
    );
    expect(confirmSpy).toHaveBeenCalledWith("Delete Your Calendars Resource?");
    confirmSpy.mockRestore();
  });

  it("deletes milestone calendar without the resource-panel confirmation prompt", async () => {
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    const props = buildProps({
      api: {
        get: vi.fn().mockResolvedValue({
          data: baseDashboardPayload({
            owned: {
              calendars: [],
              address_books: [
                {
                  id: 50,
                  display_name: "Friends",
                  uri: "friends",
                  is_sharable: false,
                  is_default: false,
                  milestone_calendars: {
                    birthdays: { enabled: true, calendar_id: 99 },
                    anniversaries: { enabled: false, calendar_id: null },
                  },
                },
              ],
            },
          }),
        }),
        patch: vi.fn().mockResolvedValue({}),
        post: vi.fn().mockResolvedValue({}),
        delete: vi.fn().mockResolvedValue({}),
      },
    });

    render(<DashboardPage {...props} />);
    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));

    await user.click(screen.getByRole("button", { name: "Delete Milestone Calendar" }));

    await waitFor(() =>
      expect(props.api.delete).toHaveBeenCalledWith("/api/calendars/99"),
    );
    expect(confirmSpy).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it("dismisses a private working set suggestion", async () => {
    const user = userEvent.setup();
    const props = buildProps();

    render(<DashboardPage {...props} />);
    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));

    await user.click(
      screen.getByRole("button", { name: "Dismiss Suggested Promotion" }),
    );

    await waitFor(() =>
      expect(props.api.post).toHaveBeenCalledWith(
        "/api/address-books/private-working-set/suggestions/44/dismiss",
      ),
    );
    expect(screen.getByTestId("private-working-set-notice")).toHaveTextContent(
      "Suggestion dismissed.",
    );
  });

  it("tracks private working set dirty baseline from load and save", async () => {
    const user = userEvent.setup();
    const props = buildProps();

    render(<DashboardPage {...props} />);
    await waitFor(() => expect(props.api.get).toHaveBeenCalledTimes(1));

    expect(screen.getByTestId("private-working-set-dirty")).toHaveTextContent(
      "clean",
    );

    await user.click(screen.getByRole("button", { name: "Mutate PWS Form" }));
    expect(screen.getByTestId("private-working-set-dirty")).toHaveTextContent(
      "dirty",
    );

    await user.click(screen.getByRole("button", { name: "Save PWS" }));
    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/address-books/private-working-set",
        expect.objectContaining({ hide_shared: false }),
      ),
    );
    await waitFor(() =>
      expect(screen.getByTestId("private-working-set-dirty")).toHaveTextContent(
        "clean",
      ),
    );
  });
});
