import React, { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, Navigate, useNavigate } from "react-router-dom";
import { buildAuthStateFromPayload } from "./authStateMapper";

/**
 * Renders the Login Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function LoginPage({
  auth,
  theme,
  api,
  extractError,
  AuthShell,
  Field,
}) {
  const { t } = useTranslation("auth");
  const navigate = useNavigate();
  const [form, setForm] = useState({ email: "", password: "" });
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  if (auth.user) {
    return <Navigate to="/" replace />;
  }

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setError("");

    try {
      const { data } = await api.post("/api/auth/login", form);
      if (data?.two_factor_required) {
        navigate("/login/2fa", { replace: true });
        return;
      }
      auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
      navigate("/");
    } catch (err) {
      setError(extractError(err, t("login.errorSignIn")));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      theme={theme}
      themeControlPlacement="window-bottom-right"
      title={t("login.title")}
      subtitle={t("login.subtitle")}
    >
      <form className="space-y-4" onSubmit={submit}>
        <Field label={t("login.email")}>
          <input
            className="input"
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
        </Field>
        <Field label={t("login.password")}>
          <input
            className="input"
            type="password"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required
          />
        </Field>
        {error ? <p className="text-sm text-app-danger">{error}</p> : null}
        <button className="btn w-full" disabled={submitting}>
          {submitting ? t("login.submitting") : t("login.submit")}
        </button>
      </form>
      <p className="mt-5 text-sm text-app-muted">
        {t("login.registerPrompt")}{" "}
        {auth.registrationEnabled ? (
          <Link to="/register" className="font-semibold text-app-accent">
            {t("login.registerLink")}
          </Link>
        ) : (
          t("login.registerDisabled")
        )}
      </p>
    </AuthShell>
  );
}
