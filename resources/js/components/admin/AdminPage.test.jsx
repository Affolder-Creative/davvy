import React from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AdminPage from "./AdminPage";
import { ToastProvider } from "../common/ToastProvider";

function AppShellStub({ children }) {
  return <div>{children}</div>;
}

function InfoCardStub({ title, value }) {
  return (
    <article>
      <h3>{title}</h3>
      <p>{value}</p>
    </article>
  );
}

function FullPageStateStub({ label }) {
  return <div>{label}</div>;
}

const WEEKDAY_OPTIONS = [
  { value: 0, label: "Sunday" },
  { value: 1, label: "Monday" },
];
const MONTH_OPTIONS = [
  { value: 1, label: "January" },
  { value: 2, label: "February" },
];
const RECOMMENDED_BACKUP_RETENTION = {
  daily: 7,
  weekly: 4,
  monthly: 12,
  yearly: 3,
};

function renderAdminPage(props) {
  return render(
    <ToastProvider>
      <AdminPage {...props} />
    </ToastProvider>,
  );
}

function buildApi({ users, resources, shares } = {}) {
  const usersData = Array.isArray(users)
    ? users
    : [
        {
          id: 2,
          name: "Admin",
          email: "admin@example.com",
          role: "admin",
          calendars_count: 1,
          address_books_count: 1,
        },
      ];
  const resourcesData = resources ?? {
    calendars: [],
    address_books: [],
    milestone_purge_visible: false,
    milestone_purge_available: false,
  };
  const sharesData = Array.isArray(shares) ? shares : [];

  const get = vi.fn((url) => {
    if (url === "/api/admin/users") {
      return Promise.resolve({
        data: {
          data: usersData,
        },
      });
    }

    if (url === "/api/admin/resources") {
      return Promise.resolve({
        data: resourcesData,
      });
    }

    if (url === "/api/admin/shares") {
      return Promise.resolve({ data: { data: sharesData } });
    }

    if (url === "/api/admin/settings/contact-change-retention") {
      return Promise.resolve({ data: { days: 90 } });
    }

    if (url === "/api/admin/settings/milestone-generation-years") {
      return Promise.resolve({ data: { years: 3 } });
    }

    if (url === "/api/admin/settings/backups") {
      return Promise.resolve({
        data: {
          enabled: false,
          local_enabled: true,
          local_path: "/tmp/davvy/backups",
          s3_enabled: false,
          s3_disk: "s3",
          s3_prefix: "davvy-backups",
          timezone: "UTC",
          schedule_times: ["02:30"],
          weekly_day: 0,
          monthly_day: 1,
          yearly_month: 1,
          yearly_day: 1,
          retention_daily: 7,
          retention_weekly: 4,
          retention_monthly: 12,
          retention_yearly: 3,
          last_run: {
            at: null,
            status: null,
            message: "",
          },
        },
      });
    }

    return Promise.reject(new Error(`Unexpected GET ${url}`));
  });

  const patch = vi.fn((url, payload) => {
    if (url === "/api/admin/settings/registration") {
      return Promise.resolve({
        data: {
          enabled: !!payload.enabled,
          require_approval: !!payload.enabled,
        },
      });
    }

    if (url === "/api/admin/settings/registration-approval") {
      return Promise.resolve({ data: { enabled: !!payload.enabled } });
    }

    if (url === "/api/admin/users/approve-pending") {
      return Promise.resolve({ data: { approved_count: 1 } });
    }

    if (url === "/api/admin/settings/contact-change-retention") {
      return Promise.resolve({ data: { days: Number(payload.days) } });
    }

    if (url === "/api/admin/settings/milestone-generation-years") {
      return Promise.resolve({ data: { years: Number(payload.years) } });
    }

    if (url === "/api/admin/settings/two-factor-enforcement") {
      return Promise.resolve({
        data: {
          enabled: !!payload.enabled,
          grace_period_days: 14,
        },
      });
    }

    return Promise.resolve({ data: {} });
  });

  return {
    get,
    patch,
    post: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: {} }),
  };
}

function buildProps(overrides = {}) {
  const auth = {
    user: {
      id: 1,
      name: "Owner",
      email: "owner@example.com",
      role: "admin",
    },
    registrationEnabled: false,
    registrationApprovalRequired: false,
    ownerShareManagementEnabled: false,
    davCompatibilityModeEnabled: false,
    contactManagementEnabled: true,
    contactChangeModerationEnabled: true,
    twoFactorEnforcementEnabled: false,
    setAuth: vi.fn(),
  };

  return {
    auth,
    theme: {},
    api: buildApi(),
    extractError: vi.fn((_, fallback) => fallback),
    AppShell: AppShellStub,
    InfoCard: InfoCardStub,
    AdminFeatureToggle: ({ label, enabled, onClick }) => (
      <button type="button" onClick={onClick}>
        {label}: {enabled ? "On" : "Off"}
      </button>
    ),
    FullPageState: FullPageStateStub,
    Field: ({ label, children }) => (
      <label>
        <span>{label}</span>
        {children}
      </label>
    ),
    PermissionBadge: ({ permission }) => <span>{permission}</span>,
    buildTimezoneGroups: () => [
      {
        label: "Common",
        options: [{ value: "UTC", label: "UTC" }],
      },
    ],
    parseBackupScheduleTimes: (value) =>
      String(value ?? "")
        .split(",")
        .map((item) => item.trim())
        .filter(Boolean),
    isRecommendedBackupRetention: ({ daily, weekly, monthly, yearly }) =>
      Number(daily) === RECOMMENDED_BACKUP_RETENTION.daily &&
      Number(weekly) === RECOMMENDED_BACKUP_RETENTION.weekly &&
      Number(monthly) === RECOMMENDED_BACKUP_RETENTION.monthly &&
      Number(yearly) === RECOMMENDED_BACKUP_RETENTION.yearly,
    areBackupConfigSnapshotsEqual: (left, right) =>
      JSON.stringify(left) === JSON.stringify(right),
    formatAdminTimestamp: () => "Mar 1, 2026",
    MILESTONE_PURGE_SUMMARY_AUTO_HIDE_MS: 6000,
    BACKUP_DRAWER_ANIMATION_MS: 220,
    WEEKDAY_OPTIONS,
    MONTH_OPTIONS,
    RECOMMENDED_BACKUP_RETENTION,
    ...overrides,
  };
}

describe("AdminPage", () => {
  it("loads admin data and renders the control center", async () => {
    const props = buildProps();

    renderAdminPage(props);

    expect(screen.getByText("Loading admin data...")).toBeInTheDocument();

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    expect(screen.getByText("Admin Control Panel")).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Create User" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Assign Share Access")).toBeInTheDocument();
  });

  it("toggles registration and shows toasts when saving retention and horizon", async () => {
    const user = userEvent.setup();
    const props = buildProps();

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    await user.click(screen.getByRole("button", { name: /public registration/i }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/registration",
        { enabled: true },
      ),
    );

    expect(props.auth.setAuth).toHaveBeenCalledTimes(1);

    await user.click(
      screen.getByRole("button", { name: /require registration approval/i }),
    );

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/registration-approval",
        { enabled: false },
      ),
    );

    const retentionInput = screen.getAllByRole("spinbutton")[0];
    await user.clear(retentionInput);
    await user.type(retentionInput, "120");
    await user.click(screen.getByRole("button", { name: "Save Retention" }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/contact-change-retention",
        { days: 120 },
      ),
    );
    expect(
      await screen.findByText("Queue retention updated to 120 day(s)."),
    ).toBeInTheDocument();

    const milestoneInput = screen.getAllByRole("spinbutton")[1];
    await user.clear(milestoneInput);
    await user.type(milestoneInput, "4");
    await user.click(screen.getByRole("button", { name: "Save Horizon" }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/milestone-generation-years",
        { years: 4 },
      ),
    );
    expect(
      await screen.findByText(
        "Milestone generation horizon updated to 4 year(s).",
      ),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /2fa enforcement/i }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/two-factor-enforcement",
        { enabled: true },
      ),
    );
  });

  it("supports user list search, role/2FA filters, and compact detail toggles", async () => {
    const user = userEvent.setup();
    const props = buildProps({
      api: buildApi({
        users: [
          {
            id: 2,
            name: "Davvy Admin",
            email: "admin@davvy.local",
            role: "admin",
            calendars_count: 3,
            address_books_count: 4,
            two_factor_enabled: true,
          },
          {
            id: 3,
            name: "John Doe",
            email: "john.doe@davvy.local",
            role: "regular",
            calendars_count: 1,
            address_books_count: 1,
            two_factor_enabled: false,
          },
          {
            id: 4,
            name: "Jane Doe",
            email: "jane.doe@davvy.local",
            role: "regular",
            calendars_count: 1,
            address_books_count: 1,
            two_factor_enabled: false,
          },
        ],
      }),
    });

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    expect(screen.getByText("Users: 3 | Showing: 3")).toBeInTheDocument();
    expect(screen.queryByText(/Role: admin \| Calendars:/)).not.toBeInTheDocument();

    const userSearchInput = screen.getByLabelText("Search users");
    const roleFilter = screen.getByLabelText("Filter users by role");
    const twoFactorFilter = screen.getByLabelText("Filter users by 2FA status");

    await user.type(userSearchInput, "jane");
    expect(screen.getByText("Jane Doe")).toBeInTheDocument();
    expect(screen.queryByText("John Doe")).not.toBeInTheDocument();
    expect(screen.getByText("Users: 3 | Showing: 1")).toBeInTheDocument();

    await user.clear(userSearchInput);
    await user.selectOptions(roleFilter, "admin");
    expect(screen.getByText("Davvy Admin")).toBeInTheDocument();
    expect(screen.queryByText("Jane Doe")).not.toBeInTheDocument();
    expect(screen.queryByText("John Doe")).not.toBeInTheDocument();

    await user.selectOptions(roleFilter, "all");
    await user.selectOptions(twoFactorFilter, "disabled");
    expect(screen.queryByText("Davvy Admin")).not.toBeInTheDocument();
    expect(screen.getByText("Jane Doe")).toBeInTheDocument();
    expect(screen.getByText("John Doe")).toBeInTheDocument();

    await user.selectOptions(twoFactorFilter, "all");
    await user.click(screen.getByRole("button", { name: "Show details" }));
    expect(screen.getByText(/Role: admin \| Calendars:/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Hide details" }));
    expect(screen.queryByText(/Role: admin \| Calendars:/)).not.toBeInTheDocument();
  });

  it("optionally bulk-approves pending users when disabling registration approval", async () => {
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    const props = buildProps({
      auth: {
        user: {
          id: 1,
          name: "Owner",
          email: "owner@example.com",
          role: "admin",
        },
        registrationEnabled: true,
        registrationApprovalRequired: true,
        ownerShareManagementEnabled: false,
        davCompatibilityModeEnabled: false,
        contactManagementEnabled: true,
        contactChangeModerationEnabled: true,
        setAuth: vi.fn(),
      },
      api: buildApi({
        users: [
          {
            id: 3,
            name: "Pending User",
            email: "pending@example.com",
            role: "regular",
            is_approved: false,
            calendars_count: 0,
            address_books_count: 0,
          },
        ],
      }),
    });

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    await user.click(
      screen.getByRole("button", { name: /require registration approval/i }),
    );

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/settings/registration-approval",
        { enabled: false },
      ),
    );

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith(
        "/api/admin/users/approve-pending",
      ),
    );

    expect(
      await screen.findByText("Approved 1 pending account(s)."),
    ).toBeInTheDocument();
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    confirmSpy.mockRestore();
  });

  it("deletes a user with typed confirmation and optional ownership transfer", async () => {
    const user = userEvent.setup();
    const props = buildProps({
      api: buildApi({
        users: [
          {
            id: 2,
            name: "Alice",
            email: "alice@example.com",
            role: "regular",
            calendars_count: 2,
            address_books_count: 2,
          },
          {
            id: 3,
            name: "Bob",
            email: "bob@example.com",
            role: "regular",
            calendars_count: 1,
            address_books_count: 1,
          },
        ],
      }),
    });
    props.api.delete.mockResolvedValue({
      data: {
        ok: true,
        deleted_user_id: 2,
        transferred_to_user_id: 3,
        transferred: {
          calendars: 2,
          address_books: 2,
          contacts: 1,
        },
      },
    });

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    await user.click(screen.getAllByRole("button", { name: "Delete" })[0]);

    const dialog = await screen.findByRole("dialog", {
      name: /delete alice/i,
    });
    const transferSelect = within(dialog).getByRole("combobox");
    const confirmInput = within(dialog).getByRole("textbox");
    const deleteAction = within(dialog).getByRole("button", {
      name: "Delete User",
    });

    expect(deleteAction).toBeDisabled();

    await user.selectOptions(transferSelect, "3");
    await user.type(confirmInput, "owner@example.com");

    expect(deleteAction).not.toBeDisabled();

    await user.click(deleteAction);

    await waitFor(() =>
      expect(props.api.delete).toHaveBeenCalledWith("/api/admin/users/2", {
        data: {
          confirmation_email: "owner@example.com",
          transfer_owner_id: 3,
        },
      }),
    );
  });

  it("groups share list entries by resource and renders recipient rows", async () => {
    const user = userEvent.setup();
    const props = buildProps({
      api: buildApi({
        resources: {
          calendars: [],
          address_books: [
            {
              id: 2,
              display_name: "Shared Team Contacts",
              owner: {
                id: 10,
                name: "Jordan Owner",
                email: "owner@example.com",
              },
            },
          ],
          milestone_purge_visible: false,
          milestone_purge_available: false,
        },
        shares: [
          {
            id: 42,
            resource_type: "address_book",
            resource_id: 2,
            permission: "read_only",
            owner: {
              id: 10,
              name: "Jordan Owner",
              email: "owner@example.com",
            },
            shared_with: {
              id: 11,
              name: "Avery Recipient",
              email: "recipient@example.com",
            },
          },
          {
            id: 43,
            resource_type: "address_book",
            resource_id: 2,
            permission: "editor",
            owner: {
              id: 10,
              name: "Jordan Owner",
              email: "owner@example.com",
            },
            shared_with: {
              id: 12,
              name: "Morgan Editor",
              email: "editor@example.com",
            },
          },
        ],
      }),
    });

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    expect(screen.getAllByText("Shared Team Contacts")).toHaveLength(1);
    expect(screen.getByText("Shared with: 2")).toBeInTheDocument();
    expect(
      screen.getByText((_, node) => {
        const text = node?.textContent?.trim() ?? "";
        return text === "Owner: Jordan Owner (owner@example.com)";
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Show recipients" }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Show recipients" }));
    expect(
      screen.getByText((_, node) => {
        const text = node?.textContent?.trim() ?? "";
        return text === "Shared with: Avery Recipient (recipient@example.com)";
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByText((_, node) => {
        const text = node?.textContent?.trim() ?? "";
        return text === "Shared with: Morgan Editor (editor@example.com)";
      }),
    ).toBeInTheDocument();
    expect(screen.getByText("read_only")).toBeInTheDocument();
    expect(screen.getByText("editor")).toBeInTheDocument();
  });

  it("supports share list search, filters, and global recipient toggle on grouped cards", async () => {
    const user = userEvent.setup();
    const props = buildProps({
      api: buildApi({
        resources: {
          calendars: [
            {
              id: 7,
              display_name: "Engineering Calendar",
              owner: {
                id: 20,
                name: "Calendar Owner",
                email: "calendar.owner@example.com",
              },
            },
          ],
          address_books: [
            {
              id: 2,
              display_name: "Shared Team Contacts",
              owner: {
                id: 10,
                name: "Jordan Owner",
                email: "owner@example.com",
              },
            },
          ],
          milestone_purge_visible: false,
          milestone_purge_available: false,
        },
        shares: [
          {
            id: 42,
            resource_type: "address_book",
            resource_id: 2,
            permission: "read_only",
            owner: {
              id: 10,
              name: "Jordan Owner",
              email: "owner@example.com",
            },
            shared_with: {
              id: 11,
              name: "Avery Recipient",
              email: "recipient@example.com",
            },
          },
          {
            id: 43,
            resource_type: "address_book",
            resource_id: 2,
            permission: "editor",
            owner: {
              id: 10,
              name: "Jordan Owner",
              email: "owner@example.com",
            },
            shared_with: {
              id: 12,
              name: "Morgan Editor",
              email: "editor@example.com",
            },
          },
          {
            id: 44,
            resource_type: "calendar",
            resource_id: 7,
            permission: "admin",
            owner: {
              id: 20,
              name: "Calendar Owner",
              email: "calendar.owner@example.com",
            },
            shared_with: {
              id: 13,
              name: "Casey Admin",
              email: "admin@example.com",
            },
          },
        ],
      }),
    });

    renderAdminPage(props);

    await waitFor(() =>
      expect(props.api.get).toHaveBeenCalledWith("/api/admin/users"),
    );

    const searchInput = screen.getByLabelText("Search shared resources");
    const typeFilter = screen.getByLabelText(
      "Filter shared resources by type",
    );
    const permissionFilter = screen.getByLabelText(
      "Filter shared resources by permission",
    );

    await user.type(searchInput, "Morgan");
    expect(screen.getByText("Shared Team Contacts")).toBeInTheDocument();
    expect(
      screen.queryByText("Engineering Calendar"),
    ).not.toBeInTheDocument();

    await user.clear(searchInput);
    await user.selectOptions(typeFilter, "calendar");
    expect(screen.getByText("Engineering Calendar")).toBeInTheDocument();
    expect(screen.queryByText("Shared Team Contacts")).not.toBeInTheDocument();

    await user.selectOptions(permissionFilter, "editor");
    expect(
      screen.getByText("No shared resources match your filters."),
    ).toBeInTheDocument();

    await user.selectOptions(typeFilter, "all");
    await user.selectOptions(permissionFilter, "all");

    await user.click(screen.getByRole("button", { name: "Show recipients" }));
    expect(
      screen.getByRole("button", { name: "Hide recipients" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText((_, node) => {
        const text = node?.textContent?.trim() ?? "";
        return text === "Shared with: Avery Recipient (recipient@example.com)";
      }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Hide recipients" }));
    expect(
      screen.queryByText((_, node) => {
        const text = node?.textContent?.trim() ?? "";
        return text === "Shared with: Avery Recipient (recipient@example.com)";
      }),
    ).not.toBeInTheDocument();
  });
});
