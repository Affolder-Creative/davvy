import React, { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import DashboardPrivateWorkingSetPanel from "./DashboardPrivateWorkingSetPanel";

function PrivateWorkingSetHarness({
  privateWorkingSet,
  initialForm,
  contactChangeModerationEnabled = true,
}) {
  const [privateWorkingSetForm, setPrivateWorkingSetForm] = useState(initialForm);

  return (
    <>
      <DashboardPrivateWorkingSetPanel
        privateWorkingSet={privateWorkingSet}
        privateWorkingSetForm={privateWorkingSetForm}
        setPrivateWorkingSetForm={setPrivateWorkingSetForm}
        privateWorkingSetNotice=""
        savingPrivateWorkingSet={false}
        pullingPrivateWorkingSet={false}
        promotingPrivateCardId={null}
        dismissingSuggestionLinkId={null}
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
        name: "Require review queue for self promotions",
      }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByText(
        "When moderation is enabled, your promotions are always queued for review.",
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
        "Review queue moderation is disabled globally. Your promotions apply directly.",
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

    const checkbox = screen.getByRole("checkbox", {
      name: "Require review queue for self promotions",
    });
    expect(checkbox).toBeEnabled();

    await user.click(checkbox);
    expect(formState()).toMatchObject({
      require_review_for_self_promotions: false,
    });
  });
});
