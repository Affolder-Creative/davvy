import React, { useEffect, useMemo, useRef, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import { useTranslation } from "react-i18next";
import { buildAuthStateFromPayload } from "../auth/authStateMapper";
import { setI18nLocale } from "../../i18n";
import { setApiLocale } from "../../lib/api";
import { buildLocaleOptions } from "../../lib/locale";

/**
 * Renders the Profile Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ProfilePage({
  auth,
  theme,
  api,
  extractError,
  AppShell,
  InfoCard,
  Field,
  copyTextToClipboard,
}) {
  const { t, i18n } = useTranslation("profile");
  const [passwordSubmitting, setPasswordSubmitting] = useState(false);
  const [passwordError, setPasswordError] = useState("");
  const [passwordSuccess, setPasswordSuccess] = useState("");
  const [passwordForm, setPasswordForm] = useState({
    current_password: "",
    password: "",
    password_confirmation: "",
  });

  const [securityBusy, setSecurityBusy] = useState(false);
  const [securityError, setSecurityError] = useState("");
  const [securitySuccess, setSecuritySuccess] = useState("");

  const [twoFactorSetup, setTwoFactorSetup] = useState(null);
  const [twoFactorCode, setTwoFactorCode] = useState("");
  const [twoFactorActionCode, setTwoFactorActionCode] = useState("");
  const [backupCodes, setBackupCodes] = useState([]);
  const [backupCodesCopyState, setBackupCodesCopyState] = useState("idle");
  const backupCodesFieldRef = useRef(null);

  const [appPasswords, setAppPasswords] = useState([]);
  const [appPasswordLoading, setAppPasswordLoading] = useState(false);
  const [appPasswordName, setAppPasswordName] = useState("");
  const [appPasswordCode, setAppPasswordCode] = useState("");
  const [appPasswordPlaintext, setAppPasswordPlaintext] = useState("");
  const [localeSubmitting, setLocaleSubmitting] = useState(false);
  const [localeError, setLocaleError] = useState("");
  const [localeSuccess, setLocaleSuccess] = useState("");
  const [localeFormValue, setLocaleFormValue] = useState(
    auth.locale || auth.fallbackLocale || "en",
  );

  const graceDeadlineLabel = useMemo(() => {
    if (!auth.twoFactorGraceExpiresAt) {
      return null;
    }

    const parsed = new Date(auth.twoFactorGraceExpiresAt);
    if (Number.isNaN(parsed.getTime())) {
      return null;
    }

    return parsed.toLocaleString(
      auth.locale || auth.fallbackLocale || i18n.resolvedLanguage || "en",
    );
  }, [
    auth.twoFactorGraceExpiresAt,
    auth.locale,
    auth.fallbackLocale,
    i18n.resolvedLanguage,
  ]);
  const backupCodesText = useMemo(() => backupCodes.join("\n"), [backupCodes]);
  const supportedLocales =
    Array.isArray(auth.supportedLocales) && auth.supportedLocales.length > 0
      ? auth.supportedLocales
      : [auth.fallbackLocale || "en"];
  const localeOptions = useMemo(
    () =>
      buildLocaleOptions(supportedLocales, {
        fallbackLocale: auth.fallbackLocale || "en",
      }),
    [supportedLocales, auth.fallbackLocale],
  );

  useEffect(() => {
    if (backupCodesCopyState === "idle") {
      return undefined;
    }

    const timer = window.setTimeout(
      () => setBackupCodesCopyState("idle"),
      1800,
    );
    return () => window.clearTimeout(timer);
  }, [backupCodesCopyState]);

  useEffect(() => {
    setLocaleFormValue(auth.locale || auth.fallbackLocale || "en");
  }, [auth.locale, auth.fallbackLocale]);

  useEffect(() => {
    if (!localeSuccess) {
      return undefined;
    }

    const timer = window.setTimeout(() => setLocaleSuccess(""), 2200);
    return () => window.clearTimeout(timer);
  }, [localeSuccess]);

  const updateLocale = async (event) => {
    event.preventDefault();
    setLocaleSubmitting(true);
    setLocaleError("");
    setLocaleSuccess("");

    try {
      const { data } = await api.patch("/api/auth/locale", {
        locale: localeFormValue,
      });

      auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
      setApiLocale(data.locale, {
        supportedLocales: data.supported_locales,
        fallbackLocale: data.fallback_locale,
      });
      setI18nLocale(data.locale, {
        supportedLocales: data.supported_locales,
        fallbackLocale: data.fallback_locale,
      });
      setLocaleSuccess(t("locale.saved"));
    } catch (err) {
      setLocaleError(extractError(err, t("errors.update_locale")));
    } finally {
      setLocaleSubmitting(false);
    }
  };

  const selectAllBackupCodes = () => {
    if (!backupCodesFieldRef.current) {
      return;
    }

    backupCodesFieldRef.current.focus();
    backupCodesFieldRef.current.select();
  };

  const copyAllBackupCodes = async () => {
    if (!backupCodesText || !copyTextToClipboard) {
      return;
    }

    try {
      await copyTextToClipboard(backupCodesText);
      setBackupCodesCopyState("copied");
    } catch {
      setBackupCodesCopyState("failed");
    }
  };

  const selectAndCopyBackupCodes = async () => {
    selectAllBackupCodes();
    await copyAllBackupCodes();
  };

  const loadAppPasswords = async () => {
    setAppPasswordLoading(true);

    try {
      const { data } = await api.get("/api/auth/app-passwords");
      setAppPasswords(Array.isArray(data?.data) ? data.data : []);
    } catch (err) {
      setSecurityError(extractError(err, t("errors.load_app_passwords")));
    } finally {
      setAppPasswordLoading(false);
    }
  };

  useEffect(() => {
    if (!auth.twoFactorEnabled) {
      setAppPasswords([]);
      return;
    }

    loadAppPasswords();
  }, [auth.twoFactorEnabled]);

  const changePassword = async (event) => {
    event.preventDefault();
    setPasswordSubmitting(true);
    setPasswordError("");
    setPasswordSuccess("");

    try {
      await api.patch("/api/auth/password", passwordForm);
      setPasswordSuccess(
        // "Password updated. Use your new password for app login and DAV clients.",
        t("api.password_success"),
      );
      setPasswordForm({
        current_password: "",
        password: "",
        password_confirmation: "",
      });
    } catch (err) {
      setPasswordError(extractError(err, t("errors.update_password")));
    } finally {
      setPasswordSubmitting(false);
    }
  };

  const startTwoFactorSetup = async () => {
    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      const { data } = await api.post("/api/auth/2fa/setup");
      setTwoFactorSetup(data);
      setBackupCodes([]);
      setSecuritySuccess(
        // "Setup initialized. Scan the QR code and enter a verification code.",
        t("api.2fa_setup_success"),
      );
    } catch (err) {
      setSecurityError(extractError(err, t("errors.start_two_factor")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const enableTwoFactor = async (event) => {
    event.preventDefault();
    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      const { data } = await api.post("/api/auth/2fa/enable", {
        code: twoFactorCode,
      });
      setBackupCodes(
        Array.isArray(data?.backup_codes) ? data.backup_codes : [],
      );
      setTwoFactorCode("");
      setTwoFactorSetup(null);
      await auth.refreshAuth?.();
      // setSecuritySuccess("Two-factor authentication has been enabled.");
      setSecuritySuccess(t("api.2fa_enable_success"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.enable_two_factor")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const disableTwoFactor = async () => {
    if (!window.confirm(t("confirm_disable_2fa"))) {
      return;
    }

    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      await api.post("/api/auth/2fa/disable", {
        code: twoFactorActionCode,
      });
      setTwoFactorActionCode("");
      setBackupCodes([]);
      setAppPasswordPlaintext("");
      setAppPasswords([]);
      await auth.refreshAuth?.();
      setSecuritySuccess(
        // "Two-factor authentication has been disabled and DAV app passwords were revoked.",
        t("api.2fa_disable_success"),
      );
    } catch (err) {
      setSecurityError(extractError(err, t("errors.disable_two_factor")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const regenerateBackupCodes = async () => {
    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      const { data } = await api.post("/api/auth/2fa/backup-codes/regenerate", {
        code: twoFactorActionCode,
      });
      setBackupCodes(
        Array.isArray(data?.backup_codes) ? data.backup_codes : [],
      );
      setTwoFactorActionCode("");
      // setSecuritySuccess("Backup codes regenerated.");
      setSecuritySuccess(t("api.backup_codes_regenerated"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.regenerate_codes")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const createAppPassword = async (event) => {
    event.preventDefault();
    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      const { data } = await api.post("/api/auth/app-passwords", {
        name: appPasswordName,
        code: appPasswordCode,
      });
      setAppPasswordPlaintext(data?.token || "");
      setAppPasswordName("");
      setAppPasswordCode("");
      await loadAppPasswords();
      // setSecuritySuccess("DAV app password created.");
      setSecuritySuccess(t("api.app_password_created"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.create_app_password")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const revokeAppPassword = async (appPasswordId) => {
    setSecurityBusy(true);
    setSecurityError("");
    setSecuritySuccess("");

    try {
      await api.delete(`/api/auth/app-passwords/${appPasswordId}`, {
        data: {
          code: appPasswordCode,
        },
      });
      await loadAppPasswords();
      // setSecuritySuccess("DAV app password revoked.");
      setSecuritySuccess(t("api.app_password_revoked"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.revoke_app_password")));
    } finally {
      setSecurityBusy(false);
    }
  };

  return (
    <AppShell auth={auth} theme={theme}>
      <section className="fade-up grid gap-4 md:grid-cols-3">
        <InfoCard
          title={t("cards.0.label")}
          value={auth.user.name}
          helper={t("cards.0.description")}
        />
        <InfoCard
          title={t("cards.1.label")}
          value={auth.user.email}
          helper={t("cards.1.description")}
        />
        <InfoCard
          title={t("cards.2.label")}
          value={auth.user.role.toUpperCase()}
          helper={t("cards.2.description")}
        />
      </section>

      <section className="surface mt-6 rounded-3xl p-6">
        <h2 className="text-xl font-semibold text-app-strong">
          {t("locale.title")}
        </h2>
        <p className="mt-1 text-sm text-app-muted">{t("locale.description")}</p>
        <form
          className="mt-4 flex flex-wrap items-end gap-3"
          onSubmit={updateLocale}
        >
          <Field label={t("locale.label")}>
            <select
              className="input min-w-40"
              value={localeFormValue}
              onChange={(event) => setLocaleFormValue(event.target.value)}
              disabled={localeSubmitting}
            >
              {localeOptions.map((option) => (
                <option
                  key={option.value}
                  value={option.value}
                  lang={option.value}
                  dir={option.dir}
                >
                  {option.label}
                </option>
              ))}
            </select>
          </Field>
          <button className="btn" disabled={localeSubmitting} type="submit">
            {localeSubmitting ? t("locale.saving") : t("locale.save")}
          </button>
        </form>
        {localeError ? (
          <p className="mt-3 text-sm text-app-danger">{localeError}</p>
        ) : null}
        {localeSuccess ? (
          <p className="mt-3 text-sm text-app-accent">{localeSuccess}</p>
        ) : null}
      </section>

      <section className="surface mt-6 rounded-3xl p-6">
        <h2 className="text-xl font-semibold text-app-strong">
          {t("change_password.title")}
        </h2>
        <p className="mt-1 text-sm text-app-muted">
          {t("change_password.description")}
        </p>
        <form
          className="mt-4 grid gap-3 md:grid-cols-3"
          onSubmit={changePassword}
        >
          <Field label={t("change_password.current_label")}>
            <input
              className="input"
              type="password"
              value={passwordForm.current_password}
              onChange={(event) =>
                setPasswordForm({
                  ...passwordForm,
                  current_password: event.target.value,
                })
              }
              required
            />
          </Field>
          <Field label={t("change_password.new_label")}>
            <input
              className="input"
              type="password"
              value={passwordForm.password}
              onChange={(event) =>
                setPasswordForm({
                  ...passwordForm,
                  password: event.target.value,
                })
              }
              required
            />
          </Field>
          <Field label={t("change_password.new_label_confirm")}>
            <input
              className="input"
              type="password"
              value={passwordForm.password_confirmation}
              onChange={(event) =>
                setPasswordForm({
                  ...passwordForm,
                  password_confirmation: event.target.value,
                })
              }
              required
            />
          </Field>

          {passwordError ? (
            <p className="md:col-span-3 text-sm text-app-danger">
              {passwordError}
            </p>
          ) : null}
          {passwordSuccess ? (
            <p className="md:col-span-3 text-sm text-app-accent">
              {passwordSuccess}
            </p>
          ) : null}

          <div className="md:col-span-3 flex flex-wrap items-center gap-2">
            <button className="btn" disabled={passwordSubmitting} type="submit">
              {passwordSubmitting
                ? t("change_password.updating")
                : t("change_password.update")}
            </button>
          </div>
        </form>
      </section>

      <section className="surface mt-6 rounded-3xl p-6">
        <h2 className="text-xl font-semibold text-app-strong">
          {t("security.title")}
        </h2>
        <p className="mt-1 text-sm text-app-muted">
          {t("security.description")}
        </p>

        {auth.twoFactorMandated && !auth.twoFactorEnabled ? (
          <p className="mt-3 rounded-xl border border-app-warning-edge bg-app-warning/10 px-3 py-2 text-sm text-app-warning-text">
            {t("security.2fa_mandated")}
            {/* {graceDeadlineLabel
              ? ` Set up 2FA before ${graceDeadlineLabel}.`
              : ""}*/}
            {graceDeadlineLabel
              ? t("security.2fa_mandated_with_deadline", {
                  deadline: graceDeadlineLabel,
                })
              : ""}
          </p>
        ) : null}

        {auth.twoFactorSetupRequired ? (
          <p className="mt-3 rounded-xl border border-app-danger-edge bg-app-danger/10 px-3 py-2 text-sm text-app-danger">
            {t("security.2fa_deadline_expired")}
          </p>
        ) : null}

        {!auth.twoFactorEnabled ? (
          <div className="mt-4 space-y-4">
            <button
              className="btn-outline btn-outline-sm"
              type="button"
              onClick={startTwoFactorSetup}
              disabled={securityBusy}
            >
              {twoFactorSetup
                ? t("security.2fa_recreate")
                : t("security.2fa_start")}
            </button>

            {twoFactorSetup ? (
              <div className="rounded-2xl border border-app-edge bg-app-surface p-4">
                <p className="text-sm text-app-muted">
                  {t("security.2fa_setup_step_1")}
                </p>
                {twoFactorSetup?.otpauth_uri ? (
                  <div className="mt-3 inline-flex rounded-lg border border-app-edge bg-white p-2">
                    <QRCodeSVG
                      value={twoFactorSetup.otpauth_uri}
                      size={176}
                      title={t("security.2fa_setup_step_1_qr_code_title")}
                    />
                  </div>
                ) : null}
                <p className="mt-3 text-sm text-app-muted">
                  {t("security.2fa_setup_step_2")}
                </p>
                <code className="mt-1 block break-all rounded-lg border border-app-edge bg-app-panel px-2 py-1 text-sm text-app-strong">
                  {twoFactorSetup.manual_key}
                </code>

                <form
                  className="mt-4 flex flex-wrap items-end gap-2"
                  onSubmit={enableTwoFactor}
                >
                  <Field label={t("security.2fa_setup_step_3")}>
                    <input
                      className="input w-44"
                      value={twoFactorCode}
                      onChange={(event) => setTwoFactorCode(event.target.value)}
                      autoComplete="one-time-code"
                      required
                    />
                  </Field>
                  <button className="btn" disabled={securityBusy} type="submit">
                    {securityBusy
                      ? t("security.2fa_enabling")
                      : t("security.2fa_enable")}
                  </button>
                </form>
              </div>
            ) : null}
          </div>
        ) : (
          <div className="mt-4 space-y-4">
            <p className="text-sm text-app-accent">
              {t("security.2fa_enabled")}
            </p>
            <Field label={t("security.2fa_code")}>
              <input
                className="input max-w-xs"
                value={twoFactorActionCode}
                onChange={(event) => setTwoFactorActionCode(event.target.value)}
                autoComplete="one-time-code"
                placeholder={t("security.2fa_code_placeholder")}
              />
            </Field>
            <div className="flex flex-wrap items-center gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={regenerateBackupCodes}
                disabled={securityBusy || !twoFactorActionCode}
              >
                {t("security.regenerate_backup_codes")}
              </button>
              <button
                className="btn-outline btn-outline-sm text-app-danger"
                type="button"
                onClick={disableTwoFactor}
                disabled={securityBusy || !twoFactorActionCode}
              >
                {t("security.disable_2fa")}
              </button>
            </div>
          </div>
        )}

        {backupCodes.length > 0 ? (
          <div className="backup-codes-ticket mt-4 rounded-2xl p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-semibold text-app-strong">
                {t("security.save_backup_codes")}
              </p>
              <span className="backup-codes-ticket-label">
                {t("security.shown_once")}
              </span>
            </div>
            <p className="mt-1 text-xs text-app-muted">
              {t("security.shown_once_description")}
            </p>
            <div className="mt-3">
              <textarea
                ref={backupCodesFieldRef}
                className="input backup-codes-ticket-field min-h-36 resize-y font-mono leading-6"
                value={backupCodesText}
                rows={Math.max(4, backupCodes.length)}
                aria-label={t("security.backup_codes")}
                readOnly
                onFocus={selectAllBackupCodes}
                onClick={selectAllBackupCodes}
              />
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={selectAllBackupCodes}
              >
                {t("security.select_all")}
              </button>
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={() => void selectAndCopyBackupCodes()}
              >
                {t("security.copy_all_codes")}
              </button>
              {backupCodesCopyState === "copied" ? (
                <span className="text-xs font-semibold text-app-accent">
                  {t("security.copied_all_codes")}
                </span>
              ) : null}
              {backupCodesCopyState === "failed" ? (
                <span className="text-xs font-semibold text-app-danger">
                  {t("security.copy_failed")}
                </span>
              ) : null}
            </div>
          </div>
        ) : null}

        {auth.twoFactorEnabled ? (
          <div className="mt-6 rounded-2xl border border-app-edge bg-app-surface p-4">
            <h3 className="text-lg font-semibold text-app-strong">
              {t("security.app_passwords")}
            </h3>
            <p className="mt-1 text-sm text-app-muted">
              {t("security.app_passwords_description")}
            </p>

            <form
              className="mt-4 grid gap-3 md:grid-cols-3"
              onSubmit={createAppPassword}
            >
              <Field label={t("security.app_password_name")}>
                <input
                  className="input"
                  value={appPasswordName}
                  onChange={(event) => setAppPasswordName(event.target.value)}
                  placeholder={t("security.app_password_name_placeholder")}
                  required
                />
              </Field>
              <Field label={t("security.app_password_code")}>
                <input
                  className="input"
                  value={appPasswordCode}
                  onChange={(event) => setAppPasswordCode(event.target.value)}
                  placeholder={t("security.app_password_code_placeholder")}
                  required
                />
              </Field>
              <div className="flex items-end">
                <button className="btn" disabled={securityBusy} type="submit">
                  {t("security.app_password_create")}
                </button>
              </div>
            </form>

            {appPasswordPlaintext ? (
              <div className="mt-3 rounded-xl border border-app-warning-edge bg-app-warning/10 p-3">
                <p className="text-xs uppercase tracking-wide text-app-faint">
                  {t("security.app_password_code_shown_once")}
                </p>
                <code className="mt-1 block break-all text-sm text-app-strong">
                  {appPasswordPlaintext}
                </code>
              </div>
            ) : null}

            <div className="mt-4 space-y-2">
              {appPasswordLoading ? (
                <p className="text-sm text-app-muted">
                  {t("security.app_password_loading")}
                </p>
              ) : appPasswords.length === 0 ? (
                <p className="text-sm text-app-muted">
                  {t("security.app_password_no_created")}
                </p>
              ) : (
                appPasswords.map((appPassword) => (
                  <div
                    key={appPassword.id}
                    className="rounded-xl border border-app-edge bg-app-panel p-3"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-semibold text-app-strong">
                        {appPassword.name}
                      </p>
                      <button
                        className="text-xs font-semibold text-app-danger"
                        type="button"
                        disabled={securityBusy || !appPasswordCode}
                        onClick={() => revokeAppPassword(appPassword.id)}
                      >
                        {t("security.app_password_revoke")}
                      </button>
                    </div>
                    <p className="mt-1 text-xs text-app-faint">
                      {/* Prefix: {appPassword.token_prefix}*/}
                      {t("security.app_password_prefix", {
                        prefix: appPassword.token_prefix,
                      })}
                    </p>
                    <p className="mt-1 text-xs text-app-faint">
                      {/* Last used: {appPassword.last_used_at || "Never"}*/}
                      {t("security.app_password_last_used", {
                        last_used_at:
                          appPassword.last_used_at ||
                          t("security.app_password_never_used"),
                      })}
                    </p>
                  </div>
                ))
              )}
            </div>
          </div>
        ) : null}

        {securityError ? (
          <p className="mt-3 text-sm text-app-danger">{securityError}</p>
        ) : null}
        {securitySuccess ? (
          <p className="mt-3 text-sm text-app-accent">{securitySuccess}</p>
        ) : null}
      </section>
    </AppShell>
  );
}
