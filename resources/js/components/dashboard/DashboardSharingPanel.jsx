import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Dashboard Sharing Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardSharingPanel({
  shareForm,
  setShareForm,
  shareableResourceOptions,
  targets,
  outgoing,
  onSaveShare,
  onDeleteShare,
  PermissionBadge,
}) {
  const { t } = useTranslation("dashboard");

  return (
    <section className="surface mt-6 rounded-3xl p-6">
      <h2 className="text-xl font-semibold text-app-strong">
        {t("sharing.title")}
      </h2>
      <p className="mt-1 text-sm text-app-muted">
        {t("sharing.subtitle")}
      </p>
      <form className="mt-4 grid gap-3 md:grid-cols-4" onSubmit={onSaveShare}>
        <select
          className="input"
          value={shareForm.resource_type}
          onChange={(event) =>
            setShareForm({
              ...shareForm,
              resource_type: event.target.value,
              resource_id: "",
            })
          }
        >
          <option value="calendar">{t("sharing.resourceCalendar")}</option>
          <option value="address_book">{t("sharing.resourceAddressBook")}</option>
        </select>
        <select
          className="input"
          value={shareForm.resource_id}
          onChange={(event) =>
            setShareForm({ ...shareForm, resource_id: event.target.value })
          }
          required
        >
          <option value="">{t("sharing.selectSharableResource")}</option>
          {shareableResourceOptions.map((resource) => (
            <option key={resource.id} value={resource.id}>
              {resource.display_name}
            </option>
          ))}
        </select>
        <select
          className="input"
          value={shareForm.shared_with_id}
          onChange={(event) =>
            setShareForm({
              ...shareForm,
              shared_with_id: event.target.value,
            })
          }
          required
        >
          <option value="">{t("sharing.selectUser")}</option>
          {targets.map((target) => (
            <option key={target.id} value={target.id}>
              {target.name} ({target.email})
            </option>
          ))}
        </select>
        <div className="flex gap-2">
          <select
            className="input"
            value={shareForm.permission}
            onChange={(event) =>
              setShareForm({ ...shareForm, permission: event.target.value })
            }
          >
            <option value="read_only">{t("sharing.permReadOnly")}</option>
            <option value="editor">{t("sharing.permEditor")}</option>
            <option value="admin">{t("sharing.permAdmin")}</option>
          </select>
          <button className="btn" type="submit">
            {t("sharing.share")}
          </button>
        </div>
      </form>

      <div className="mt-5 space-y-2">
        {outgoing.length === 0 ? (
          <p className="text-sm text-app-faint">{t("sharing.noOutgoing")}</p>
        ) : (
          outgoing.map((share) => (
            <div
              key={share.id}
              className="rounded-xl border border-app-edge bg-app-surface p-3 text-sm"
            >
              <div className="flex items-center justify-between gap-3">
                <p className="font-semibold text-app-strong">
                  {share.resource_type} #{share.resource_id}
                </p>
                <PermissionBadge permission={share.permission} />
              </div>
              <p className="text-app-muted">
                {t("sharing.sharedWith", {
                  name: share.shared_with?.name,
                  email: share.shared_with?.email,
                })}
              </p>
              <button
                className="mt-2 text-xs font-semibold text-app-danger"
                onClick={() => onDeleteShare(share.id)}
              >
                {t("sharing.revoke")}
              </button>
            </div>
          ))
        )}
      </div>
    </section>
  );
}
