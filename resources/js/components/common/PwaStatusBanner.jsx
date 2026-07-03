import React from "react";
import { useTranslation } from "react-i18next";

export default function PwaStatusBanner({
  isOnline,
  updateAvailable,
  offlineReady,
  onActivateUpdate,
  onDismissOfflineReady,
}) {
  const { t } = useTranslation("common");

  if (!isOnline) {
    return (
      <StatusShell tone="warning">
        <p>{t("pwa.offline")}</p>
      </StatusShell>
    );
  }

  if (updateAvailable) {
    return (
      <StatusShell>
        <p>{t("pwa.updateReady")}</p>
        <button
          className="btn-outline btn-outline-sm shrink-0"
          type="button"
          onClick={onActivateUpdate}
        >
          {t("pwa.reload")}
        </button>
      </StatusShell>
    );
  }

  if (offlineReady) {
    return (
      <StatusShell>
        <p>{t("pwa.offlineReady")}</p>
        <button
          className="btn-outline btn-outline-sm shrink-0"
          type="button"
          onClick={onDismissOfflineReady}
        >
          {t("pwa.dismiss")}
        </button>
      </StatusShell>
    );
  }

  return null;
}

function StatusShell({ children, tone = "info" }) {
  const toneClass =
    tone === "warning"
      ? "border-app-warn-edge bg-app-warn-surface"
      : "border-app-accent-edge bg-app-surface";

  return (
    <div className="mx-auto max-w-7xl px-4 pt-3 sm:px-6 lg:px-8">
      <div
        className={`${toneClass} flex flex-col gap-3 rounded-2xl border px-4 py-3 text-sm font-semibold text-app-strong shadow-sm sm:flex-row sm:items-center sm:justify-between`}
        role="status"
        aria-live="polite"
      >
        {children}
      </div>
    </div>
  );
}
