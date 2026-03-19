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
        title={t("overview.endpointTitle")}
        value={`${window.location.origin}/dav`}
        helper={t("overview.endpointHelper")}
        copyable
      />
      <InfoCard
        title={t("overview.principalTitle")}
        value={`principals/${auth.user.id}`}
        helper={t("overview.principalHelper")}
      />
      <InfoCard
        title={t("overview.roleTitle")}
        value={auth.user.role.toUpperCase()}
        helper={t("overview.roleHelper")}
      />
    </section>
  );
}
