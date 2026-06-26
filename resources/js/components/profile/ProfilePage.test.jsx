import React from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ProfilePage from "./ProfilePage";

const webPushMocks = vi.hoisted(() => ({
  supported: false,
  permission: "default",
  subscription: null,
  isWebPushSupported: vi.fn(() => false),
  notificationPermission: vi.fn(() => "default"),
  currentPushSubscription: vi.fn(() => Promise.resolve(null)),
  subscribeToWebPush: vi.fn(),
  unsubscribeFromWebPush: vi.fn(),
  serializePushSubscription: vi.fn(),
}));

vi.mock("../../lib/webPush", () => ({
  isWebPushSupported: webPushMocks.isWebPushSupported,
  notificationPermission: webPushMocks.notificationPermission,
  currentPushSubscription: webPushMocks.currentPushSubscription,
  subscribeToWebPush: webPushMocks.subscribeToWebPush,
  unsubscribeFromWebPush: webPushMocks.unsubscribeFromWebPush,
  serializePushSubscription: webPushMocks.serializePushSubscription,
}));

function AppShellStub({ children }) {
  return <div>{children}</div>;
}

function InfoCardStub({ title, value, helper }) {
  return (
    <article>
      <h3>{title}</h3>
      <p>{value}</p>
      <p>{helper}</p>
    </article>
  );
}

function FieldStub({ label, children }) {
  return (
    <label>
      <span>{label}</span>
      {children}
    </label>
  );
}

function buildProps(overrides = {}) {
  return {
    auth: {
      user: {
        name: "Admin User",
        email: "admin@example.com",
        role: "admin",
      },
      twoFactorEnabled: false,
      locale: "en",
      supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
      fallbackLocale: "en",
      setAuth: vi.fn(),
      refreshAuth: vi.fn().mockResolvedValue(undefined),
      webPushEnabled: false,
      webPushAvailable: false,
    },
    theme: {},
    api: {
      get: vi.fn().mockResolvedValue({ data: {} }),
      patch: vi.fn().mockResolvedValue({}),
      post: vi.fn().mockResolvedValue({ data: {} }),
      put: vi.fn().mockResolvedValue({ data: {} }),
      delete: vi.fn().mockResolvedValue({ data: {} }),
    },
    extractError: vi.fn((_, fallback) => fallback),
    AppShell: AppShellStub,
    InfoCard: InfoCardStub,
    Field: FieldStub,
    copyTextToClipboard: vi.fn().mockResolvedValue(undefined),
    ...overrides,
  };
}

describe("ProfilePage", () => {
  beforeEach(() => {
    webPushMocks.supported = false;
    webPushMocks.permission = "default";
    webPushMocks.subscription = null;
    webPushMocks.isWebPushSupported.mockImplementation(() => webPushMocks.supported);
    webPushMocks.notificationPermission.mockImplementation(
      () => webPushMocks.permission,
    );
    webPushMocks.currentPushSubscription.mockImplementation(() =>
      Promise.resolve(webPushMocks.subscription),
    );
    webPushMocks.subscribeToWebPush.mockReset();
    webPushMocks.unsubscribeFromWebPush.mockReset();
    webPushMocks.serializePushSubscription.mockReset();
  });

  it("renders profile cards", () => {
    render(<ProfilePage {...buildProps()} />);

    expect(screen.getByText("Name")).toBeInTheDocument();
    expect(screen.getByText("Admin User")).toBeInTheDocument();
    expect(screen.getByText("Email")).toBeInTheDocument();
    expect(screen.getByText("admin@example.com")).toBeInTheDocument();
    expect(screen.getByText("Role")).toBeInTheDocument();
    expect(screen.getByText("ADMIN")).toBeInTheDocument();
  });

  it("submits password changes successfully and resets form", async () => {
    const user = userEvent.setup();
    const props = buildProps();

    render(<ProfilePage {...props} />);

    const current = screen.getByLabelText("Current password");
    const next = screen.getByLabelText("New password");
    const confirm = screen.getByLabelText("Confirm new password");

    await user.type(current, "oldpass");
    await user.type(next, "newpass123");
    await user.type(confirm, "newpass123");
    await user.click(screen.getByRole("button", { name: "Update Password" }));

    await waitFor(() =>
      expect(props.api.patch).toHaveBeenCalledWith("/api/auth/password", {
        current_password: "oldpass",
        password: "newpass123",
        password_confirmation: "newpass123",
      }),
    );
    expect(
      screen.getByText(
        "Password updated. Use your new password for app login and DAV clients.",
      ),
    ).toBeInTheDocument();
    expect(current).toHaveValue("");
    expect(next).toHaveValue("");
    expect(confirm).toHaveValue("");
  });

  it("shows extracted error message when password update fails", async () => {
    const user = userEvent.setup();
    const err = new Error("boom");
    const props = buildProps({
      api: {
        patch: vi.fn().mockRejectedValue(err),
      },
      extractError: vi.fn(() => "Failed to update password."),
    });

    render(<ProfilePage {...props} />);

    await user.type(screen.getByLabelText("Current password"), "oldpass");
    await user.type(screen.getByLabelText("New password"), "newpass123");
    await user.type(screen.getByLabelText("Confirm new password"), "newpass123");
    await user.click(screen.getByRole("button", { name: "Update Password" }));

    expect(await screen.findByText("Failed to update password.")).toBeInTheDocument();
    expect(props.extractError).toHaveBeenCalledWith(
      err,
      "Unable to update password.",
    );
  });

  it("consolidates backup codes and only copies via the copy action", async () => {
    const user = userEvent.setup();
    const backupCodes = ["2GXW-3KXY", "V8XS-Q5CZ", "TZKH-32Z5", "EF75-BRXJ"];
    const props = buildProps({
      api: {
        patch: vi.fn().mockResolvedValue({}),
        post: vi
          .fn()
          .mockResolvedValueOnce({
            data: {
              otpauth_uri:
                "otpauth://totp/Davvy:admin@example.com?secret=ABC123&issuer=Davvy",
              manual_key: "ABC123",
            },
          })
          .mockResolvedValueOnce({
            data: {
              backup_codes: backupCodes,
            },
          }),
      },
    });

    render(<ProfilePage {...props} />);

    await user.click(screen.getByRole("button", { name: "Start 2FA Setup" }));
    await user.type(screen.getByLabelText("3. Verification code"), "123456");
    await user.click(screen.getByRole("button", { name: "Enable 2FA" }));

    const backupCodeField = await screen.findByLabelText("Backup codes");
    const expectedCodes = backupCodes.join("\n");

    expect(backupCodeField).toHaveValue(expectedCodes);

    await user.click(backupCodeField);

    expect(props.copyTextToClipboard).not.toHaveBeenCalled();
    expect(backupCodeField.selectionStart).toBe(0);
    expect(backupCodeField.selectionEnd).toBe(expectedCodes.length);

    await user.click(screen.getByRole("button", { name: "Copy All Codes" }));

    expect(props.copyTextToClipboard).toHaveBeenCalledWith(expectedCodes);
    expect(backupCodeField.selectionStart).toBe(0);
    expect(backupCodeField.selectionEnd).toBe(expectedCodes.length);
    expect(screen.getByText("Copied all codes.")).toBeInTheDocument();
  });

  it("supports select-all without forcing clipboard writes", async () => {
    const user = userEvent.setup();
    const backupCodes = ["AAAA-BBBB", "CCCC-DDDD"];
    const copyTextToClipboard = vi.fn().mockResolvedValue(undefined);
    const props = buildProps({
      copyTextToClipboard,
      api: {
        patch: vi.fn().mockResolvedValue({}),
        post: vi
          .fn()
          .mockResolvedValueOnce({
            data: {
              otpauth_uri:
                "otpauth://totp/Davvy:admin@example.com?secret=ABC123&issuer=Davvy",
              manual_key: "ABC123",
            },
          })
          .mockResolvedValueOnce({
            data: {
              backup_codes: backupCodes,
            },
          }),
      },
    });

    render(<ProfilePage {...props} />);

    await user.click(screen.getByRole("button", { name: "Start 2FA Setup" }));
    await user.type(screen.getByLabelText("3. Verification code"), "123456");
    await user.click(screen.getByRole("button", { name: "Enable 2FA" }));

    const backupCodeField = await screen.findByLabelText("Backup codes");
    const expectedCodes = backupCodes.join("\n");

    await user.click(screen.getByRole("button", { name: "Select All" }));

    expect(copyTextToClipboard).not.toHaveBeenCalled();
    expect(backupCodeField.selectionStart).toBe(0);
    expect(backupCodeField.selectionEnd).toBe(expectedCodes.length);
  });

  it("updates locale preference through the profile language selector", async () => {
    const user = userEvent.setup();
    const setAuth = vi.fn();
    const props = buildProps({
      auth: {
        user: {
          name: "Admin User",
          email: "admin@example.com",
          role: "admin",
        },
        twoFactorEnabled: false,
        locale: "en",
        supportedLocales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
        fallbackLocale: "en",
        setAuth,
        refreshAuth: vi.fn().mockResolvedValue(undefined),
      },
      api: {
        patch: vi.fn().mockResolvedValue({
          data: {
            user: {
              id: 1,
              name: "Admin User",
              email: "admin@example.com",
              role: "admin",
            },
            locale: "fr",
            supported_locales: ["de", "en", "es", "fr", "it", "ja", "pt", "zh"],
            fallback_locale: "en",
          },
        }),
        post: vi.fn().mockResolvedValue({ data: {} }),
      },
    });

    render(<ProfilePage {...props} />);

    expect(screen.getByRole("option", { name: "Deutsch" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Français" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Italiano" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "日本語" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Português" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "中文" })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Language"), "fr");
    await user.click(screen.getByRole("button", { name: "Save Language" }));

    expect(props.api.patch).toHaveBeenCalledWith("/api/auth/locale", {
      locale: "fr",
    });
    await waitFor(() => expect(setAuth).toHaveBeenCalledTimes(1));
    expect(await screen.findByText("Langue mise à jour.")).toBeInTheDocument();
  });

  it("renders locale options from auth.supportedLocales", () => {
    const props = buildProps({
      auth: {
        user: {
          name: "Admin User",
          email: "admin@example.com",
          role: "admin",
        },
        twoFactorEnabled: false,
        locale: "es",
        supportedLocales: ["en", "es"],
        fallbackLocale: "en",
        setAuth: vi.fn(),
        refreshAuth: vi.fn().mockResolvedValue(undefined),
      },
    });

    render(<ProfilePage {...props} />);

    const languageSelect = screen.getByRole("combobox");
    const optionLabels = Array.from(languageSelect.options).map(
      (option) => option.textContent,
    );

    expect(optionLabels).toEqual(["English", "Español"]);
    expect(screen.queryByRole("option", { name: "Deutsch" })).not.toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "Français" })).not.toBeInTheDocument();
  });

  it("enables push notifications for the current device", async () => {
    const user = userEvent.setup();
    webPushMocks.supported = true;
    webPushMocks.permission = "default";
    const browserSubscription = { endpoint: "https://push.example.test/abc" };
    const subscriptionPayload = {
      endpoint: "https://push.example.test/abc",
      keys: {
        p256dh: "p256dh-key",
        auth: "auth-token",
      },
      content_encoding: "aes128gcm",
    };
    webPushMocks.subscribeToWebPush.mockResolvedValue(browserSubscription);
    webPushMocks.serializePushSubscription.mockReturnValue(subscriptionPayload);

    const props = buildProps({
      auth: {
        user: {
          name: "Admin User",
          email: "admin@example.com",
          role: "admin",
        },
        twoFactorEnabled: false,
        locale: "en",
        supportedLocales: ["en"],
        fallbackLocale: "en",
        setAuth: vi.fn(),
        refreshAuth: vi.fn().mockResolvedValue(undefined),
        webPushEnabled: true,
        webPushAvailable: true,
      },
      api: {
        get: vi.fn().mockResolvedValue({
          data: {
            enabled: true,
            available: true,
            public_key: "public-key",
            subscription_count: 0,
            preferences: {
              review_queue_enabled: false,
              admin_pending_registration_enabled: false,
              admin_backup_operations_enabled: false,
            },
          },
        }),
        patch: vi.fn().mockResolvedValue({}),
        post: vi.fn().mockResolvedValue({
          data: {
            subscription_count: 1,
            preferences: {
              review_queue_enabled: true,
              admin_pending_registration_enabled: true,
              admin_backup_operations_enabled: true,
            },
          },
        }),
        put: vi.fn().mockResolvedValue({ data: {} }),
        delete: vi.fn().mockResolvedValue({ data: {} }),
      },
    });

    render(<ProfilePage {...props} />);

    await waitFor(() =>
      expect(
        screen.getAllByText("Ready to enable on this device.").length,
      ).toBeGreaterThan(0),
    );
    await user.click(screen.getByRole("button", { name: "Enable This Device" }));

    await waitFor(() =>
      expect(webPushMocks.subscribeToWebPush).toHaveBeenCalledWith("public-key"),
    );
    expect(webPushMocks.serializePushSubscription).toHaveBeenCalledWith(
      browserSubscription,
    );
    expect(props.api.post).toHaveBeenCalledWith(
      "/api/notifications/web-push/subscriptions",
      subscriptionPayload,
    );
    expect(
      await screen.findByText("Push notifications enabled for this device."),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/review queue/i)).toBeChecked();
  });
});
