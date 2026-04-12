import React, { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import DashboardSharingPanel from "./DashboardSharingPanel";

function PermissionBadgeStub({ permission }) {
  return <span data-testid={`permission-${permission}`}>{permission}</span>;
}

function SharingPanelHarness({
  initialForm,
  onSaveShare,
  onDeleteShare,
  shareableResourceOptions,
  targets,
  outgoing,
}) {
  const [shareForm, setShareForm] = useState(initialForm);

  return (
    <>
      <DashboardSharingPanel
        shareForm={shareForm}
        setShareForm={setShareForm}
        shareableResourceOptions={shareableResourceOptions}
        targets={targets}
        outgoing={outgoing}
        onSaveShare={onSaveShare}
        onDeleteShare={onDeleteShare}
        PermissionBadge={PermissionBadgeStub}
      />
      <pre data-testid="share-form-state">{JSON.stringify(shareForm)}</pre>
    </>
  );
}

function currentFormState() {
  return JSON.parse(screen.getByTestId("share-form-state").textContent ?? "{}");
}

describe("DashboardSharingPanel", () => {
  it("submits share form and updates form state", async () => {
    const user = userEvent.setup();
    const onSaveShare = vi.fn((event) => event.preventDefault());

    render(
      <SharingPanelHarness
        initialForm={{
          resource_type: "calendar",
          resource_id: "",
          shared_with_id: "",
          permission: "read_only",
        }}
        onSaveShare={onSaveShare}
        onDeleteShare={vi.fn()}
        shareableResourceOptions={[
          { id: 10, display_name: "Team Calendar" },
          { id: 11, display_name: "Family Calendar" },
        ]}
        targets={[{ id: 7, name: "Pat", email: "pat@example.com" }]}
        outgoing={[]}
      />,
    );

    const selects = screen.getAllByRole("combobox");
    await user.selectOptions(selects[0], "address_book");
    expect(currentFormState()).toMatchObject({
      resource_type: "address_book",
      resource_id: "",
    });

    await user.selectOptions(selects[1], "11");
    await user.selectOptions(selects[2], "7");
    await user.selectOptions(selects[3], "admin");
    await user.click(screen.getByRole("button", { name: "Share" }));

    expect(currentFormState()).toMatchObject({
      resource_type: "address_book",
      resource_id: "11",
      shared_with_id: "7",
      permission: "admin",
    });
    expect(onSaveShare).toHaveBeenCalledTimes(1);
  });

  it("hides the recipients toggle when there are no outgoing shares", () => {
    render(
      <SharingPanelHarness
        initialForm={{
          resource_type: "calendar",
          resource_id: "",
          shared_with_id: "",
          permission: "read_only",
        }}
        onSaveShare={vi.fn((event) => event.preventDefault())}
        onDeleteShare={vi.fn()}
        shareableResourceOptions={[]}
        targets={[]}
        outgoing={[]}
      />,
    );

    expect(
      screen.queryByRole("button", { name: "Show recipients" }),
    ).not.toBeInTheDocument();
  });

  it("renders outgoing share entries and revokes by id", async () => {
    const user = userEvent.setup();
    const onDeleteShare = vi.fn();

    render(
      <SharingPanelHarness
        initialForm={{
          resource_type: "calendar",
          resource_id: "",
          shared_with_id: "",
          permission: "read_only",
        }}
        onSaveShare={vi.fn((event) => event.preventDefault())}
        onDeleteShare={onDeleteShare}
        shareableResourceOptions={[]}
        targets={[]}
        outgoing={[
          {
            id: 99,
            resource_type: "calendar",
            resource_id: 10,
            resource_display_name: "Team Calendar",
            permission: "editor",
            shared_with: { name: "Pat", email: "pat@example.com" },
          },
          {
            id: 100,
            resource_type: "calendar",
            resource_id: 10,
            resource_display_name: "Team Calendar",
            permission: "read_only",
            shared_with: { name: "Alex", email: "alex@example.com" },
          },
        ]}
      />,
    );

    expect(screen.getByText("Team Calendar")).toBeInTheDocument();
    expect(screen.getByText("Shared with: 2")).toBeInTheDocument();
    expect(screen.queryByText("Shared with: Pat")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Show recipients" }));

    expect(screen.getByText("Shared with: Pat")).toBeInTheDocument();
    expect(screen.getByText(/\(pat@example\.com\)/i)).toBeInTheDocument();
    expect(screen.getByText("Shared with: Alex")).toBeInTheDocument();
    expect(screen.getByText(/\(alex@example\.com\)/i)).toBeInTheDocument();
    expect(screen.getByTestId("permission-editor")).toBeInTheDocument();

    const revokeButtons = screen.getAllByRole("button", { name: "Revoke" });
    await user.click(revokeButtons[0]);
    expect(onDeleteShare).toHaveBeenCalledWith(99);
  });

  it("falls back to type and id when outgoing share display name is unavailable", () => {
    render(
      <SharingPanelHarness
        initialForm={{
          resource_type: "calendar",
          resource_id: "",
          shared_with_id: "",
          permission: "read_only",
        }}
        onSaveShare={vi.fn((event) => event.preventDefault())}
        onDeleteShare={vi.fn()}
        shareableResourceOptions={[]}
        targets={[]}
        outgoing={[
          {
            id: 101,
            resource_type: "address_book",
            resource_id: 7,
            resource_display_name: null,
            permission: "read_only",
            shared_with: { name: "Alex", email: "alex@example.com" },
          },
        ]}
      />,
    );

    expect(screen.getByText("Address Book #7")).toBeInTheDocument();
  });
});
