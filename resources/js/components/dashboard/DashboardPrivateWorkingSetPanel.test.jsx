import React, { useMemo, useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import DashboardPrivateWorkingSetPanel from "./DashboardPrivateWorkingSetPanel";

function PrivateWorkingSetHarness({
  privateWorkingSet,
  initialForm,
  contactChangeModerationEnabled = true,
  promotionHistory = [],
  initialDirty = false,
  deriveDirtyFromForm = false,
}) {
  const [privateWorkingSetForm, setPrivateWorkingSetForm] = useState(initialForm);
  const privateWorkingSetIsDirty = useMemo(() => {
    if (!deriveDirtyFromForm) {
      return initialDirty;
    }

    return JSON.stringify(privateWorkingSetForm) !== JSON.stringify(initialForm);
  }, [deriveDirtyFromForm, initialDirty, initialForm, privateWorkingSetForm]);

  return (
    <>
      <DashboardPrivateWorkingSetPanel
        privateWorkingSet={privateWorkingSet}
        privateWorkingSetForm={privateWorkingSetForm}
        privateWorkingSetIsDirty={privateWorkingSetIsDirty}
        setPrivateWorkingSetForm={setPrivateWorkingSetForm}
        privateWorkingSetNotice=""
        savingPrivateWorkingSet={false}
        pullingPrivateWorkingSet={false}
        promotingPrivateCardId={null}
        dismissingSuggestionLinkId={null}
        privateWorkingSetPromotionHistory={promotionHistory}
        contactChangeModerationEnabled={contactChangeModerationEnabled}
        onSavePrivateWorkingSet={vi.fn((event) => event.preventDefault())}
        onPullPrivateWorkingSet={vi.fn()}
        onPromotePrivateCard={vi.fn()}
        onDismissSuggestedPromotion={vi.fn()}
      />
      <pre data-testid="private-form-state">
        {JSON.stringify(privateWorkingSetForm)}
      </pre>
    </>
  );
}

function formState() {
  return JSON.parse(screen.getByTestId("private-form-state").textContent ?? "{}");
}

function basePrivateWorkingSet(overrides = {}) {
  return {
    enabled: true,
    hide_shared: true,
    include_owned_sharable_sources: true,
    require_review_for_self_promotions: false,
    can_manage_self_review_policy: false,
    effective_require_review_for_self_promotions: true,
    private_address_book_id: 10,
    private_address_book_uri: "private-working-set",
    private_display_name: "Private Working Set",
    selected_source_ids: [],
    source_options: [],
    linked_cards: [],
    suggested_promotions: [],
    ...overrides,
  };
}

describe("DashboardPrivateWorkingSetPanel", () => {
  it("disables Save while pristine and enables it after a form change", async () => {
    const user = userEvent.setup();

    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet()}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
        deriveDirtyFromForm={true}
      />,
    );

    const saveButton = screen.getByRole("button", {
      name: /Save private working set settings/i,
    });
    expect(saveButton).toBeDisabled();

    await user.click(
      screen.getByRole("checkbox", {
        name: /Use private working set for shared contacts/i,
      }),
    );

    expect(saveButton).toBeEnabled();
    expect(screen.getByText("You have unsaved changes.")).toBeInTheDocument();
  });

  it("starts in simple mode and reveals advanced controls when expanded", async () => {
    const user = userEvent.setup();

    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          can_manage_self_review_policy: true,
          effective_require_review_for_self_promotions: true,
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
      />,
    );

    expect(
      screen.queryByRole("checkbox", {
        name: /Hide selected source books in my DAV apps/i,
      }),
    ).not.toBeInTheDocument();
    expect(screen.getByText("Selected: 0")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Refresh from source books/i }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Reset from source books/i }),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: /Show advanced options/i }),
    );

    expect(
      screen.getByRole("checkbox", {
        name: /Hide selected source books in my DAV apps/i,
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Reset from source books/i }),
    ).toBeInTheDocument();
    expect(screen.getByText("Last promotion results")).toBeInTheDocument();
  });

  it("updates selected source count when source selection changes", async () => {
    const user = userEvent.setup();

    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          source_options: [
            {
              id: 1,
              display_name: "Family",
              owner_name: "Admin",
              owner_email: "admin@example.com",
              scope: "shared",
              can_write: true,
            },
            {
              id: 2,
              display_name: "Friends",
              owner_name: "Admin",
              owner_email: "admin@example.com",
              scope: "shared",
              can_write: true,
            },
          ],
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [1],
        }}
      />,
    );

    expect(screen.getByText("Selected: 1")).toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: /Friends/i }));
    expect(screen.getByText("Selected: 2")).toBeInTheDocument();
  });

  it("caps source, suggested, and linked lists with internal scrolling", () => {
    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          source_options: [
            {
              id: 11,
              display_name: "Family",
              owner_name: "Admin",
              owner_email: "admin@example.com",
              scope: "shared",
              can_write: true,
            },
          ],
          suggested_promotions: [
            {
              link_id: 21,
              private_card_id: 31,
              source_card_uri: "family-31",
              display_name: "Alex",
              suggested_fields: ["email"],
            },
          ],
          linked_cards: [
            {
              private_card_id: 41,
              source_card_uri: "family-41",
              display_name: "Pat",
            },
          ],
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
      />,
    );

    expect(screen.getByTestId("pws-source-list")).toHaveClass(
      "max-h-[32rem]",
      "overflow-y-auto",
    );
    expect(screen.getByTestId("pws-suggested-list")).toHaveClass(
      "max-h-[32rem]",
      "overflow-y-auto",
    );
    expect(screen.getByTestId("pws-linked-list")).toHaveClass(
      "max-h-[32rem]",
      "overflow-y-auto",
    );
  });

  it("hides self-review policy toggle for non-admin users and shows enforced queue note", () => {
    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          can_manage_self_review_policy: false,
          effective_require_review_for_self_promotions: true,
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
        contactChangeModerationEnabled={true}
      />,
    );

    expect(
      screen.queryByRole("checkbox", {
        name: /Queue my own promotions for review/i,
      }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByText(
        "Review moderation is currently enabled.",
      ),
    ).toBeInTheDocument();
  });

  it("shows direct-apply note for non-admin users when moderation is globally disabled", () => {
    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          can_manage_self_review_policy: false,
          effective_require_review_for_self_promotions: false,
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: false,
          source_ids: [],
        }}
        contactChangeModerationEnabled={false}
      />,
    );

    expect(
      screen.getByText(
        "Review moderation is currently disabled.",
      ),
    ).toBeInTheDocument();
  });

  it("keeps self-review policy toggle editable for admin users", async () => {
    const user = userEvent.setup();

    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet({
          can_manage_self_review_policy: true,
          effective_require_review_for_self_promotions: true,
        })}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
        contactChangeModerationEnabled={true}
      />,
    );

    await user.click(
      screen.getByRole("button", { name: /Show advanced options/i }),
    );

    const checkbox = screen.getByRole("checkbox", {
      name: /Queue my own promotions for review/i,
    });
    expect(checkbox).toBeEnabled();

    await user.click(checkbox);
    expect(formState()).toMatchObject({
      require_review_for_self_promotions: false,
    });
  });

  it("renders recent promotion history rows", () => {
    render(
      <PrivateWorkingSetHarness
        privateWorkingSet={basePrivateWorkingSet()}
        initialForm={{
          enabled: true,
          hide_shared: true,
          include_owned_sharable_sources: true,
          require_review_for_self_promotions: true,
          source_ids: [],
        }}
        promotionHistory={[
          {
            id: "row-1",
            display_name: "RQ Test Person",
            source_card_uri: "rq-test-person",
            queued: true,
            occurred_at: "2026-04-09T22:00:00.000Z",
          },
        ]}
      />,
    );

    expect(screen.getByText("Last promotion results")).toBeInTheDocument();
    expect(screen.getByText("RQ Test Person")).toBeInTheDocument();
    expect(screen.getByText("Queued for review")).toBeInTheDocument();
    expect(screen.getByText(/Source URI: rq-test-person/i)).toBeInTheDocument();
  });
});
