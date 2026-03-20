import React from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ProfilePage from "./ProfilePage";

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
      supportedLocales: ["de", "en", "es", "fr", "it", "pt"],
      fallbackLocale: "en",
      setAuth: vi.fn(),
      refreshAuth: vi.fn().mockResolvedValue(undefined),
    },
    theme: {},
    api: {
      patch: vi.fn().mockResolvedValue({}),
      post: vi.fn().mockResolvedValue({ data: {} }),
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
        supportedLocales: ["de", "en", "es", "fr", "it", "pt"],
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
            supported_locales: ["de", "en", "es", "fr", "it", "pt"],
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
    expect(screen.getByRole("option", { name: "Português" })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText("Language"), "fr");
    await user.click(screen.getByRole("button", { name: "Save Language" }));

    expect(props.api.patch).toHaveBeenCalledWith("/api/auth/locale", {
      locale: "fr",
    });
    await waitFor(() => expect(setAuth).toHaveBeenCalledTimes(1));
    expect(screen.getByText("Language updated.")).toBeInTheDocument();
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
});
