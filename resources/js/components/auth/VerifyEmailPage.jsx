import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, Navigate, useNavigate, useSearchParams } from "react-router-dom";
import { buildAuthStateFromPayload } from "./authStateMapper";

export default function VerifyEmailPage({
  auth,
  theme,
  api,
  extractError,
  AuthShell,
}) {
  const { t } = useTranslation("auth");
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get("token") || "";
  const [status, setStatus] = useState(token ? "verifying" : "error");
  const [message, setMessage] = useState(
    token ? t("verifyEmail.verifying") : t("verifyEmail.missingToken"),
  );

  if (auth.user) {
    return <Navigate to="/" replace />;
  }

  useEffect(() => {
    if (!token) {
      return;
    }

    let active = true;

    const run = async () => {
      try {
        const { data } = await api.post("/api/auth/verify-email", {
          token,
        });

        if (!active) {
          return;
        }

        if (data?.user) {
          auth.setAuth(buildAuthStateFromPayload(data, { user: data.user }));
          navigate("/");
          return;
        }

        setStatus("success");
        setMessage(
          data?.message || t("verifyEmail.success"),
        );
      } catch (err) {
        if (!active) {
          return;
        }

        setStatus("error");
        setMessage(
          extractError(err, t("verifyEmail.error")),
        );
      }
    };

    run();

    return () => {
      active = false;
    };
  }, [auth, api, extractError, navigate, token]);

  return (
    <AuthShell
      theme={theme}
      themeControlPlacement="window-bottom-right"
      title={t("verifyEmail.title")}
      subtitle={t("verifyEmail.subtitle")}
    >
      <p
        className={
          status === "error"
            ? "text-sm text-app-danger"
            : "text-sm text-app-muted"
        }
      >
        {message}
      </p>
      <p className="mt-5 text-sm text-app-muted">
        {t("verifyEmail.returnTo")}{" "}
        <Link to="/login" className="font-semibold text-app-accent">
          {t("verifyEmail.signIn")}
        </Link>
      </p>
    </AuthShell>
  );
}
