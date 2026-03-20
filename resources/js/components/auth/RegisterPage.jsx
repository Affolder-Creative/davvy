import React, { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, Navigate, useNavigate } from "react-router-dom";
import { buildAuthStateFromPayload } from "./authStateMapper";

/**
 * Renders the Register Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function RegisterPage({
  auth,
  theme,
  api,
  extractError,
  AuthShell,
  Field,
}) {
  const { t } = useTranslation("auth");
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [actionLink, setActionLink] = useState("");
  const [submitting, setSubmitting] = useState(false);

  if (auth.user) {
    return <Navigate to="/" replace />;
  }

  if (!auth.registrationEnabled) {
    return <Navigate to="/login" replace />;
  }

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setError("");
    setNotice("");
    setActionLink("");

    try {
      const { data } = await api.post("/api/auth/register", form);
      if (data?.user) {
        auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
        navigate("/");

        return;
      }

      if (data?.registration_pending_approval) {
        setForm({
          name: "",
          email: "",
          password: "",
          password_confirmation: "",
        });
        setNotice(
          data?.message ||
            t("register.pendingApprovalNotice"),
        );
        setActionLink(
          typeof data?.verification_url === "string"
            ? data.verification_url
            : "",
        );

        return;
      }

      if (data?.registration_pending_verification) {
        setForm({
          name: "",
          email: "",
          password: "",
          password_confirmation: "",
        });
        setNotice(
          data?.message ||
            t("register.pendingVerificationNotice"),
        );
        setActionLink(
          typeof data?.verification_url === "string"
            ? data.verification_url
            : "",
        );

        return;
      }

      setError(t("register.errorUnexpected"));
    } catch (err) {
      setError(extractError(err, t("register.errorRegister")));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      theme={theme}
      themeControlPlacement="window-bottom-right"
      title={t("register.title")}
      subtitle={t("register.subtitle")}
    >
      <form className="space-y-4" onSubmit={submit}>
        <Field label={t("register.name")}>
          <input
            className="input"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            required
          />
        </Field>
        <Field label={t("register.email")}>
          <input
            className="input"
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
        </Field>
        <Field label={t("register.password")}>
          <input
            className="input"
            type="password"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required
          />
        </Field>
        <Field label={t("register.passwordConfirm")}>
          <input
            className="input"
            type="password"
            value={form.password_confirmation}
            onChange={(e) =>
              setForm({ ...form, password_confirmation: e.target.value })
            }
            required
          />
        </Field>
        {error ? <p className="text-sm text-app-danger">{error}</p> : null}
        {notice ? <p className="text-sm text-app-accent">{notice}</p> : null}
        {actionLink ? (
          <p className="text-xs text-app-muted">
            {t("register.verificationLink")}{" "}
            <a
              href={actionLink}
              className="font-semibold text-app-accent underline"
            >
              {t("register.openVerification")}
            </a>
          </p>
        ) : null}
        <button className="btn w-full" disabled={submitting}>
          {submitting ? t("register.submitting") : t("register.submit")}
        </button>
      </form>
      <p className="mt-5 text-sm text-app-muted">
        {t("register.alreadyRegistered")}{" "}
        <Link to="/login" className="font-semibold text-app-accent">
          {t("register.signIn")}
        </Link>
      </p>
    </AuthShell>
  );
}
