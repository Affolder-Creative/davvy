import React, { useMemo, useState } from "react";
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
  const [shareRecipientsExpanded, setShareRecipientsExpanded] = useState(false);
  const groupedOutgoing = useMemo(() => {
    const grouped = [];
    const groupsByKey = new Map();

    (Array.isArray(outgoing) ? outgoing : []).forEach((share) => {
      const resourceType =
        share.resource_type === "address_book" ? "address_book" : "calendar";
      const resourceId = Number(share.resource_id);
      const key = `${resourceType}:${
        Number.isFinite(resourceId) ? resourceId : String(share.resource_id)
      }`;

      if (!groupsByKey.has(key)) {
        const initialGroup = {
          key,
          resource_type: resourceType,
          resource_id: share.resource_id,
          resource_display_name: share.resource_display_name ?? null,
          shares: [],
        };
        groupsByKey.set(key, initialGroup);
        grouped.push(initialGroup);
      }

      const group = groupsByKey.get(key);
      if (
        (!group.resource_display_name || group.resource_display_name === "") &&
        share.resource_display_name
      ) {
        group.resource_display_name = share.resource_display_name;
      }

      group.shares.push(share);
    });

    return grouped;
  }, [outgoing]);

  const resourceLabelFor = (resourceGroup) => {
    const displayName = String(resourceGroup?.resource_display_name ?? "").trim();
    if (displayName !== "") {
      return displayName;
    }

    const typeLabel =
      resourceGroup?.resource_type === "address_book"
        ? t("sharing.resourceAddressBook")
        : t("sharing.resourceCalendar");

    return `${typeLabel} #${resourceGroup?.resource_id}`;
  };

  const renderShareIdentity = (translationKey, user) => {
    const name = String(user?.name ?? "").trim();
    const email = String(user?.email ?? "").trim();
    if (email === "") {
      return t(translationKey, { name, email });
    }

    const emailMarker = "__DAVVY_SHARE_EMAIL__";
    const translated = t(translationKey, {
      name,
      email: emailMarker,
    });

    if (!translated.includes(emailMarker)) {
      return t(translationKey, { name, email });
    }

    const [beforeEmail, ...afterEmailParts] = translated.split(emailMarker);
    let beforeEmailText = beforeEmail;
    let afterEmailText = afterEmailParts.join(emailMarker);

    if (beforeEmailText.endsWith("(") && afterEmailText.startsWith(")")) {
      beforeEmailText = beforeEmailText.slice(0, -1);
      afterEmailText = afterEmailText.slice(1);
    }

    return (
      <>
        {beforeEmailText}
        <span className="text-xs text-app-faint">({email})</span>
        {afterEmailText}
      </>
    );
  };

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

      <div className="mt-5 flex justify-end">
        <button
          className="btn-outline btn-outline-sm"
          type="button"
          onClick={() => setShareRecipientsExpanded((prev) => !prev)}
          disabled={groupedOutgoing.length === 0}
        >
          {shareRecipientsExpanded
            ? t("sharing.hideRecipients")
            : t("sharing.showRecipients")}
        </button>
      </div>

      <div className="mt-3 max-h-[32rem] space-y-2 overflow-y-auto pr-1">
        {groupedOutgoing.length === 0 ? (
          <p className="text-sm text-app-faint">{t("sharing.noOutgoing")}</p>
        ) : (
          groupedOutgoing.map((shareGroup) => (
            <div
              key={shareGroup.key}
              className="rounded-xl border border-app-edge bg-app-surface p-3 text-sm"
            >
              <div className="flex items-start justify-between gap-3">
                <p className="font-semibold text-app-strong">
                  {resourceLabelFor(shareGroup)}
                </p>
                <span className="rounded-full border border-app-edge bg-app-surface px-2 py-0.5 text-xs text-app-faint">
                  {t("sharing.sharedWithCount", { count: shareGroup.shares.length })}
                </span>
              </div>
              {!shareRecipientsExpanded ? null : (
                <div className="mt-2 space-y-2">
                  {shareGroup.shares.map((share) => (
                    <div
                      key={share.id}
                      className="rounded-lg border border-app-edge px-3 py-2"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <p className="text-app-muted">
                          {renderShareIdentity("sharing.sharedWith", share.shared_with)}
                        </p>
                        <PermissionBadge permission={share.permission} />
                      </div>
                      <button
                        className="mt-2 text-xs font-semibold text-app-danger"
                        onClick={() => onDeleteShare(share.id)}
                      >
                        {t("sharing.revoke")}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))
        )}
      </div>
    </section>
  );
}
