import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, Navigate, useNavigate } from "react-router-dom";
import { buildAuthStateFromPayload } from "./authStateMapper";

/**
 * Renders the Login Two Factor Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function LoginTwoFactorPage({
  auth,
  theme,
  api,
  extractError,
  AuthShell,
  Field,
}) {
  const { t } = useTranslation("auth");
  const navigate = useNavigate();
  const [code, setCode] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let active = true;

    const checkChallenge = async () => {
      try {
        const { data } = await api.get("/api/auth/login/2fa/status");
        if (!active) {
          return;
        }

        if (!data?.required) {
          navigate("/login", { replace: true });
          return;
        }

        setLoading(false);
      } catch {
        if (!active) {
          return;
        }

        navigate("/login", { replace: true });
      }
    };

    checkChallenge();

    return () => {
      active = false;
    };
  }, [api, navigate]);

  if (auth.user) {
    return <Navigate to="/" replace />;
  }

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setError("");

    try {
      const { data } = await api.post("/api/auth/login/2fa", { code });
      auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
      navigate("/", { replace: true });
    } catch (err) {
      setError(extractError(err, t("twoFactor.errorVerify")));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthShell
      theme={theme}
      themeControlPlacement="window-bottom-right"
      title={t("twoFactor.title")}
      subtitle={t("twoFactor.subtitle")}
    >
      {loading ? (
        <p className="text-sm text-app-muted">{t("twoFactor.checking")}</p>
      ) : (
        <form className="space-y-4" onSubmit={submit}>
          <Field label={t("twoFactor.codeLabel")}>
            <input
              className="input"
              value={code}
              onChange={(event) => setCode(event.target.value)}
              autoComplete="one-time-code"
              autoFocus
              required
            />
          </Field>
          {error ? <p className="text-sm text-app-danger">{error}</p> : null}
          <button className="btn w-full" disabled={submitting}>
            {submitting ? t("twoFactor.submitting") : t("twoFactor.submit")}
          </button>
        </form>
      )}

      <p className="mt-5 text-sm text-app-muted">
        {t("twoFactor.returnPrompt")}{" "}
        <Link to="/login" className="font-semibold text-app-accent">
          {t("twoFactor.returnLink")}
        </Link>
      </p>
    </AuthShell>
  );
}
