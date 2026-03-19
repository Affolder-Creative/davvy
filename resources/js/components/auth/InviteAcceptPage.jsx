import React, { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, Navigate, useNavigate, useSearchParams } from "react-router-dom";
import { buildAuthStateFromPayload } from "./authStateMapper";

export default function InviteAcceptPage({
  auth,
  theme,
  api,
  extractError,
  AuthShell,
  Field,
}) {
  const { t } = useTranslation("auth");
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get("token") || "";
  const [form, setForm] = useState({
    password: "",
    password_confirmation: "",
  });
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [submitting, setSubmitting] = useState(false);

  if (auth.user) {
    return <Navigate to="/" replace />;
  }

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setError("");
    setNotice("");

    if (!token) {
      setSubmitting(false);
      setError(t("invite.missingToken"));
      return;
    }

    try {
      const { data } = await api.post("/api/auth/invite/accept", {
        token,
        ...form,
      });

      if (data?.user) {
        auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
        navigate("/");
        return;
      }

      if (data?.registration_pending_approval) {
        setNotice(
          data?.message ||
            t("register.pendingApprovalNotice"),
        );
        return;
      }

      setError(t("invite.errorUnexpected"));
    } catch (err) {
      setError(extractError(err, t("invite.errorAccept")));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      theme={theme}
      themeControlPlacement="window-bottom-right"
      title={t("invite.title")}
      subtitle={t("invite.subtitle")}
    >
      <form className="space-y-4" onSubmit={submit}>
        <Field label={t("invite.password")}>
          <input
            className="input"
            type="password"
            value={form.password}
            onChange={(event) =>
              setForm((prev) => ({ ...prev, password: event.target.value }))
            }
            required
          />
        </Field>
        <Field label={t("invite.passwordConfirm")}>
          <input
            className="input"
            type="password"
            value={form.password_confirmation}
            onChange={(event) =>
              setForm((prev) => ({
                ...prev,
                password_confirmation: event.target.value,
              }))
            }
            required
          />
        </Field>
        {error ? <p className="text-sm text-app-danger">{error}</p> : null}
        {notice ? <p className="text-sm text-app-accent">{notice}</p> : null}
        <button className="btn w-full" disabled={submitting}>
          {submitting ? t("invite.submitting") : t("invite.submit")}
        </button>
      </form>
      <p className="mt-5 text-sm text-app-muted">
        {t("invite.alreadyActivated")}{" "}
        <Link to="/login" className="font-semibold text-app-accent">
          {t("invite.signIn")}
        </Link>
      </p>
    </AuthShell>
  );
}
