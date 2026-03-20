import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Permission Badge component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function PermissionBadge({ permission }) {
  const { t } = useTranslation("common");

  if (permission === "admin") {
    return <span className="pill pill-admin">{t("permission.admin")}</span>;
  }

  if (permission === "editor") {
    return <span className="pill pill-editor">{t("permission.editor")}</span>;
  }

  return <span className="pill pill-read">{t("permission.general")}</span>;
}
