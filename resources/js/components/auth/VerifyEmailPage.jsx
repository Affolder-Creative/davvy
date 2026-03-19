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
    token ? t("verify_email.verifying") : t("verify_email.missing_token"),
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
          data?.message || t("verify_email.success"),
        );
      } catch (err) {
        if (!active) {
          return;
        }

        setStatus("error");
        setMessage(
          extractError(err, t("verify_email.error")),
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
      title={t("verify_email.title")}
      subtitle={t("verify_email.subtitle")}
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
        {t("verify_email.return_to")}{" "}
        <Link to="/login" className="font-semibold text-app-accent">
          {t("verify_email.sign_in")}
        </Link>
      </p>
    </AuthShell>
  );
}
