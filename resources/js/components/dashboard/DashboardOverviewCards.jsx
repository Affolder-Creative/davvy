import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Dashboard Overview Cards component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardOverviewCards({ auth, InfoCard }) {
  const { t } = useTranslation("dashboard");

  return (
    <section className="fade-up grid gap-4 md:grid-cols-3">
      <InfoCard
        title={t("overview.endpoint_title")}
        value={`${window.location.origin}/dav`}
        helper={t("overview.endpoint_helper")}
        copyable
      />
      <InfoCard
        title={t("overview.principal_title")}
        value={`principals/${auth.user.id}`}
        helper={t("overview.principal_helper")}
      />
      <InfoCard
        title={t("overview.role_title")}
        value={auth.user.role.toUpperCase()}
        helper={t("overview.role_helper")}
      />
    </section>
  );
}
