import React, { useEffect, useMemo, useRef, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import { useTranslation } from "react-i18next";
import { buildAuthStateFromPayload } from "../auth/authStateMapper";
import { setI18nLocale } from "../../i18n";
import { setApiLocale } from "../../lib/api";
import { buildLocaleOptions } from "../../lib/locale";
import {
  currentPushSubscription,
  isWebPushSupported,
  notificationPermission,
  serializePushSubscription,
  subscribeToWebPush,
  unsubscribeFromWebPush,
} from "../../lib/webPush";

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
  const [webPushLoading, setWebPushLoading] = useState(false);
  const [webPushBusy, setWebPushBusy] = useState(false);
  const [webPushError, setWebPushError] = useState("");
  const [webPushSuccess, setWebPushSuccess] = useState("");
  const [webPushState, setWebPushState] = useState({
    enabled: false,
    available: false,
    publicKey: null,
    supported: false,
    permission: "default",
    subscribed: false,
    subscriptionCount: 0,
    preferences: {
      review_queue_enabled: false,
      admin_pending_registration_enabled: false,
      admin_backup_operations_enabled: false,
    },
  });

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
  const webPushSupported = useMemo(() => isWebPushSupported(), []);

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

  useEffect(() => {
    if (!webPushSuccess) {
      return undefined;
    }

    const timer = window.setTimeout(() => setWebPushSuccess(""), 2200);
    return () => window.clearTimeout(timer);
  }, [webPushSuccess]);

  const normalizeWebPushPreferences = (preferences) => ({
    review_queue_enabled: !!preferences?.review_queue_enabled,
    admin_pending_registration_enabled:
      auth.user.role === "admin" &&
      !!preferences?.admin_pending_registration_enabled,
    admin_backup_operations_enabled:
      auth.user.role === "admin" &&
      !!preferences?.admin_backup_operations_enabled,
  });

  const refreshWebPushStatus = async ({ withLoading = true } = {}) => {
    if (!auth.webPushEnabled) {
      setWebPushState((previous) => ({
        ...previous,
        enabled: false,
        available: false,
        supported: webPushSupported,
        permission: notificationPermission(),
        subscribed: false,
      }));
      return;
    }

    if (withLoading) {
      setWebPushLoading(true);
    }
    setWebPushError("");

    try {
      const [configResponse, subscription] = await Promise.all([
        api.get("/api/notifications/web-push"),
        webPushSupported ? currentPushSubscription() : Promise.resolve(null),
      ]);
      const data = configResponse.data ?? {};

      setWebPushState({
        enabled: !!data.enabled,
        available: !!data.available,
        publicKey: data.public_key || null,
        supported: webPushSupported,
        permission: notificationPermission(),
        subscribed: !!subscription,
        subscriptionCount: Number(data.subscription_count || 0),
        preferences: normalizeWebPushPreferences(data.preferences),
      });
    } catch (err) {
      setWebPushError(extractError(err, t("errors.loadWebPush")));
    } finally {
      if (withLoading) {
        setWebPushLoading(false);
      }
    }
  };

  useEffect(() => {
    void refreshWebPushStatus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [auth.webPushEnabled, webPushSupported]);

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
      await setI18nLocale(data.locale, {
        supportedLocales: data.supported_locales,
        fallbackLocale: data.fallback_locale,
      });
      setLocaleSuccess(i18n.t("locale.saved", { ns: "profile" }));
    } catch (err) {
      setLocaleError(extractError(err, t("errors.updateLocale")));
    } finally {
      setLocaleSubmitting(false);
    }
  };

  const enableWebPush = async () => {
    setWebPushBusy(true);
    setWebPushError("");
    setWebPushSuccess("");

    try {
      if (!webPushState.available || !webPushState.publicKey) {
        throw new Error(t("errors.webPushUnavailable"));
      }

      const subscription = await subscribeToWebPush(webPushState.publicKey);
      const payload = serializePushSubscription(subscription);
      if (!payload?.endpoint || !payload?.keys?.p256dh || !payload?.keys?.auth) {
        throw new Error(t("errors.webPushSubscriptionInvalid"));
      }

      const response = await api.post(
        "/api/notifications/web-push/subscriptions",
        payload,
      );

      setWebPushState((previous) => ({
        ...previous,
        permission: notificationPermission(),
        subscribed: true,
        subscriptionCount: Number(response.data?.subscription_count || 1),
        preferences: normalizeWebPushPreferences(response.data?.preferences),
      }));
      setWebPushSuccess(t("notices.webPushEnabled"));
    } catch (err) {
      setWebPushError(
        extractError(err, err?.message || t("errors.enableWebPush")),
      );
    } finally {
      setWebPushBusy(false);
    }
  };

  const disableWebPush = async () => {
    setWebPushBusy(true);
    setWebPushError("");
    setWebPushSuccess("");

    try {
      const subscription = await unsubscribeFromWebPush();
      if (subscription?.endpoint) {
        const response = await api.delete(
          "/api/notifications/web-push/subscriptions",
          {
            data: {
              endpoint: subscription.endpoint,
            },
          },
        );

        setWebPushState((previous) => ({
          ...previous,
          subscribed: false,
          permission: notificationPermission(),
          subscriptionCount: Number(response.data?.subscription_count || 0),
        }));
      } else {
        setWebPushState((previous) => ({
          ...previous,
          subscribed: false,
          permission: notificationPermission(),
        }));
      }

      setWebPushSuccess(t("notices.webPushDisabled"));
    } catch (err) {
      setWebPushError(extractError(err, t("errors.disableWebPush")));
    } finally {
      setWebPushBusy(false);
    }
  };

  const updateWebPushPreference = async (key, enabled) => {
    setWebPushBusy(true);
    setWebPushError("");
    setWebPushSuccess("");

    const nextPreferences = {
      ...webPushState.preferences,
      [key]: enabled,
    };

    try {
      const response = await api.put("/api/notifications/web-push/preferences", {
        [key]: enabled,
      });

      setWebPushState((previous) => ({
        ...previous,
        preferences: normalizeWebPushPreferences(
          response.data?.preferences ?? nextPreferences,
        ),
      }));
      setWebPushSuccess(t("notices.webPushPreferencesSaved"));
    } catch (err) {
      setWebPushError(extractError(err, t("errors.updateWebPushPreferences")));
    } finally {
      setWebPushBusy(false);
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
      setSecurityError(extractError(err, t("errors.loadAppPasswords")));
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
        t("notices.passwordSuccess"),
      );
      setPasswordForm({
        current_password: "",
        password: "",
        password_confirmation: "",
      });
    } catch (err) {
      setPasswordError(extractError(err, t("errors.updatePassword")));
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
        t("notices.2faSetupSuccess"),
      );
    } catch (err) {
      setSecurityError(extractError(err, t("errors.startTwoFactor")));
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
      setSecuritySuccess(t("notices.2faEnableSuccess"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.enableTwoFactor")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const disableTwoFactor = async () => {
    if (!window.confirm(t("confirmations.disable2fa"))) {
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
        t("notices.2faDisableSuccess"),
      );
    } catch (err) {
      setSecurityError(extractError(err, t("errors.disableTwoFactor")));
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
      setSecuritySuccess(t("notices.backupCodesRegenerated"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.regenerateCodes")));
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
      setSecuritySuccess(t("notices.appPasswordCreated"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.createAppPassword")));
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
      setSecuritySuccess(t("notices.appPasswordRevoked"));
    } catch (err) {
      setSecurityError(extractError(err, t("errors.revokeAppPassword")));
    } finally {
      setSecurityBusy(false);
    }
  };

  const webPushStatusLabel = !auth.webPushEnabled
    ? t("webPush.status.disabled")
    : !webPushState.supported
      ? t("webPush.status.unsupported")
      : !webPushState.available
        ? t("webPush.status.unavailable")
        : webPushState.permission === "denied"
          ? t("webPush.status.denied")
          : webPushState.subscribed
            ? t("webPush.status.enabled")
            : t("webPush.status.ready");
  const canEnableWebPush =
    auth.webPushEnabled &&
    webPushState.available &&
    webPushState.supported &&
    webPushState.permission !== "denied" &&
    !webPushState.subscribed;
  const canDisableWebPush = webPushState.supported && webPushState.subscribed;
  const webPushPreferencesDisabled =
    webPushBusy || !webPushState.subscribed || !webPushState.available;

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
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-xl font-semibold text-app-strong">
              {t("webPush.title")}
            </h2>
            <p className="mt-1 text-sm text-app-muted">
              {t("webPush.description")}
            </p>
          </div>
          <span className="rounded-full border border-app-edge bg-app-surface px-3 py-1 text-xs font-semibold text-app-muted">
            {webPushLoading ? t("webPush.status.loading") : webPushStatusLabel}
          </span>
        </div>

        <div className="mt-4 rounded-2xl border border-app-edge bg-app-panel p-4">
          <p className="text-sm text-app-strong">{webPushStatusLabel}</p>
          <p className="mt-1 text-sm text-app-muted">
            {t("webPush.deviceHelp")}
          </p>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            {canEnableWebPush ? (
              <button
                className="btn"
                type="button"
                onClick={enableWebPush}
                disabled={webPushBusy}
              >
                {webPushBusy
                  ? t("webPush.enabling")
                  : t("webPush.enableDevice")}
              </button>
            ) : null}
            {canDisableWebPush ? (
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={disableWebPush}
                disabled={webPushBusy}
              >
                {webPushBusy
                  ? t("webPush.disabling")
                  : t("webPush.disableDevice")}
              </button>
            ) : null}
            <button
              className="btn-outline btn-outline-sm"
              type="button"
              onClick={() => refreshWebPushStatus({ withLoading: false })}
              disabled={webPushBusy || !auth.webPushEnabled}
            >
              {t("webPush.refresh")}
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 md:grid-cols-3">
          <label className="rounded-2xl border border-app-edge bg-app-surface p-4">
            <span className="flex items-start gap-3">
              <input
                className="mt-1 h-4 w-4 accent-app-accent"
                type="checkbox"
                checked={webPushState.preferences.review_queue_enabled}
                disabled={webPushPreferencesDisabled}
                onChange={(event) =>
                  updateWebPushPreference(
                    "review_queue_enabled",
                    event.target.checked,
                  )
                }
              />
              <span>
                <span className="block text-sm font-semibold text-app-strong">
                  {t("webPush.categories.reviewQueue")}
                </span>
                <span className="mt-1 block text-sm text-app-muted">
                  {t("webPush.categories.reviewQueueHelp")}
                </span>
              </span>
            </span>
          </label>

          {auth.user.role === "admin" ? (
            <>
              <label className="rounded-2xl border border-app-edge bg-app-surface p-4">
                <span className="flex items-start gap-3">
                  <input
                    className="mt-1 h-4 w-4 accent-app-accent"
                    type="checkbox"
                    checked={
                      webPushState.preferences
                        .admin_pending_registration_enabled
                    }
                    disabled={webPushPreferencesDisabled}
                    onChange={(event) =>
                      updateWebPushPreference(
                        "admin_pending_registration_enabled",
                        event.target.checked,
                      )
                    }
                  />
                  <span>
                    <span className="block text-sm font-semibold text-app-strong">
                      {t("webPush.categories.pendingRegistration")}
                    </span>
                    <span className="mt-1 block text-sm text-app-muted">
                      {t("webPush.categories.pendingRegistrationHelp")}
                    </span>
                  </span>
                </span>
              </label>

              <label className="rounded-2xl border border-app-edge bg-app-surface p-4">
                <span className="flex items-start gap-3">
                  <input
                    className="mt-1 h-4 w-4 accent-app-accent"
                    type="checkbox"
                    checked={
                      webPushState.preferences.admin_backup_operations_enabled
                    }
                    disabled={webPushPreferencesDisabled}
                    onChange={(event) =>
                      updateWebPushPreference(
                        "admin_backup_operations_enabled",
                        event.target.checked,
                      )
                    }
                  />
                  <span>
                    <span className="block text-sm font-semibold text-app-strong">
                      {t("webPush.categories.backupOperations")}
                    </span>
                    <span className="mt-1 block text-sm text-app-muted">
                      {t("webPush.categories.backupOperationsHelp")}
                    </span>
                  </span>
                </span>
              </label>
            </>
          ) : null}
        </div>

        {webPushError ? (
          <p className="mt-3 text-sm text-app-danger">{webPushError}</p>
        ) : null}
        {webPushSuccess ? (
          <p className="mt-3 text-sm text-app-accent">{webPushSuccess}</p>
        ) : null}
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
          {t("changePassword.title")}
        </h2>
        <p className="mt-1 text-sm text-app-muted">
          {t("changePassword.description")}
        </p>
        <form
          className="mt-4 grid gap-3 md:grid-cols-3"
          onSubmit={changePassword}
        >
          <Field label={t("changePassword.currentLabel")}>
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
          <Field label={t("changePassword.newLabel")}>
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
          <Field label={t("changePassword.newLabelConfirm")}>
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
                ? t("changePassword.updating")
                : t("changePassword.update")}
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
            {t("security.2faMandated")}
            {/* {graceDeadlineLabel
              ? ` Set up 2FA before ${graceDeadlineLabel}.`
              : ""}*/}
            {graceDeadlineLabel
              ? t("security.2faMandatedWithDeadline", {
                  deadline: graceDeadlineLabel,
                })
              : ""}
          </p>
        ) : null}

        {auth.twoFactorSetupRequired ? (
          <p className="mt-3 rounded-xl border border-app-danger-edge bg-app-danger/10 px-3 py-2 text-sm text-app-danger">
            {t("security.2faDeadlineExpired")}
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
                ? t("security.2faRecreate")
                : t("security.2faStart")}
            </button>

            {twoFactorSetup ? (
              <div className="rounded-2xl border border-app-edge bg-app-surface p-4">
                <p className="text-sm text-app-muted">
                  {t("security.2faSetupStep1")}
                </p>
                {twoFactorSetup?.otpauth_uri ? (
                  <div className="mt-3 inline-flex rounded-lg border border-app-edge bg-white p-2">
                    <QRCodeSVG
                      value={twoFactorSetup.otpauth_uri}
                      size={176}
                      title={t("security.2faSetupStep1QrCodeTitle")}
                    />
                  </div>
                ) : null}
                <p className="mt-3 text-sm text-app-muted">
                  {t("security.2faSetupStep2")}
                </p>
                <code className="mt-1 block break-all rounded-lg border border-app-edge bg-app-panel px-2 py-1 text-sm text-app-strong">
                  {twoFactorSetup.manual_key}
                </code>

                <form
                  className="mt-4 flex flex-wrap items-end gap-2"
                  onSubmit={enableTwoFactor}
                >
                  <Field label={t("security.2faSetupStep3")}>
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
                      ? t("security.2faEnabling")
                      : t("security.2faEnable")}
                  </button>
                </form>
              </div>
            ) : null}
          </div>
        ) : (
          <div className="mt-4 space-y-4">
            <p className="text-sm text-app-accent">
              {t("security.2faEnabled")}
            </p>
            <Field label={t("security.2faCode")}>
              <input
                className="input max-w-xs"
                value={twoFactorActionCode}
                onChange={(event) => setTwoFactorActionCode(event.target.value)}
                autoComplete="one-time-code"
                placeholder={t("security.2faCodePlaceholder")}
              />
            </Field>
            <div className="flex flex-wrap items-center gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={regenerateBackupCodes}
                disabled={securityBusy || !twoFactorActionCode}
              >
                {t("security.regenerateBackupCodes")}
              </button>
              <button
                className="btn-outline btn-outline-sm text-app-danger"
                type="button"
                onClick={disableTwoFactor}
                disabled={securityBusy || !twoFactorActionCode}
              >
                {t("security.disable2fa")}
              </button>
            </div>
          </div>
        )}

        {backupCodes.length > 0 ? (
          <div className="backup-codes-ticket mt-4 rounded-2xl p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-semibold text-app-strong">
                {t("security.saveBackupCodes")}
              </p>
              <span className="backup-codes-ticket-label">
                {t("security.shownOnce")}
              </span>
            </div>
            <p className="mt-1 text-xs text-app-muted">
              {t("security.shownOnceDescription")}
            </p>
            <div className="mt-3">
              <textarea
                ref={backupCodesFieldRef}
                className="input backup-codes-ticket-field min-h-36 resize-y font-mono leading-6"
                value={backupCodesText}
                rows={Math.max(4, backupCodes.length)}
                aria-label={t("security.backupCodes")}
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
                {t("security.selectAll")}
              </button>
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={() => void selectAndCopyBackupCodes()}
              >
                {t("security.copyAllCodes")}
              </button>
              {backupCodesCopyState === "copied" ? (
                <span className="text-xs font-semibold text-app-accent">
                  {t("security.copiedAllCodes")}
                </span>
              ) : null}
              {backupCodesCopyState === "failed" ? (
                <span className="text-xs font-semibold text-app-danger">
                  {t("security.copyFailed")}
                </span>
              ) : null}
            </div>
          </div>
        ) : null}

        {auth.twoFactorEnabled ? (
          <div className="mt-6 rounded-2xl border border-app-edge bg-app-surface p-4">
            <h3 className="text-lg font-semibold text-app-strong">
              {t("security.appPasswords")}
            </h3>
            <p className="mt-1 text-sm text-app-muted">
              {t("security.appPasswordsDescription")}
            </p>

            <form
              className="mt-4 grid gap-3 md:grid-cols-3"
              onSubmit={createAppPassword}
            >
              <Field label={t("security.appPasswordName")}>
                <input
                  className="input"
                  value={appPasswordName}
                  onChange={(event) => setAppPasswordName(event.target.value)}
                  placeholder={t("security.appPasswordNamePlaceholder")}
                  required
                />
              </Field>
              <Field label={t("security.appPasswordCode")}>
                <input
                  className="input"
                  value={appPasswordCode}
                  onChange={(event) => setAppPasswordCode(event.target.value)}
                  placeholder={t("security.appPasswordCodePlaceholder")}
                  required
                />
              </Field>
              <div className="flex items-end">
                <button className="btn" disabled={securityBusy} type="submit">
                  {t("security.appPasswordCreate")}
                </button>
              </div>
            </form>

            {appPasswordPlaintext ? (
              <div className="mt-3 rounded-xl border border-app-warning-edge bg-app-warning/10 p-3">
                <p className="text-xs uppercase tracking-wide text-app-faint">
                  {t("security.appPasswordCodeShownOnce")}
                </p>
                <code className="mt-1 block break-all text-sm text-app-strong">
                  {appPasswordPlaintext}
                </code>
              </div>
            ) : null}

            <div className="mt-4 space-y-2">
              {appPasswordLoading ? (
                <p className="text-sm text-app-muted">
                  {t("security.appPasswordLoading")}
                </p>
              ) : appPasswords.length === 0 ? (
                <p className="text-sm text-app-muted">
                  {t("security.appPasswordNoCreated")}
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
                        {t("security.appPasswordRevoke")}
                      </button>
                    </div>
                    <p className="mt-1 text-xs text-app-faint">
                      {/* Prefix: {appPassword.token_prefix}*/}
                      {t("security.appPasswordPrefix", {
                        prefix: appPassword.token_prefix,
                      })}
                    </p>
                    <p className="mt-1 text-xs text-app-faint">
                      {/* Last used: {appPassword.last_used_at || "Never"}*/}
                      {t("security.appPasswordLastUsed", {
                        last_used_at:
                          appPassword.last_used_at ||
                          t("security.appPasswordNeverUsed"),
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
