import React, { useState } from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Resource Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ResourcePanel({
  title,
  createLabel,
  exportAllLabel,
  resourceKind,
  principalId,
  items,
  sharedItems,
  onCreate,
  form,
  setForm,
  onExportAll,
  onExportItem,
  onToggle,
  onRename,
  renderOwnedItemExtra = null,
  CopyableResourceUri,
  PermissionBadge,
  DownloadIcon,
  PencilIcon,
}) {
  const { t } = useTranslation("dashboard");
  const [editingItemId, setEditingItemId] = useState(null);
  const [nameDraft, setNameDraft] = useState("");
  const [renamingItemId, setRenamingItemId] = useState(null);

  const startEditing = (item) => {
    setEditingItemId(item.id);
    setNameDraft(item.display_name ?? "");
  };

  const cancelEditing = () => {
    setEditingItemId(null);
    setNameDraft("");
    setRenamingItemId(null);
  };

  const submitRename = async (event, item) => {
    event.preventDefault();
    const nextName = nameDraft.trim();

    if (!nextName) {
      return;
    }

    if (nextName === item.display_name) {
      cancelEditing();
      return;
    }

    setRenamingItemId(item.id);
    try {
      await onRename(item.id, nextName);
      cancelEditing();
    } catch {
      // Errors are surfaced by DashboardPage.
    } finally {
      setRenamingItemId(null);
    }
  };

  return (
    <section className="surface rounded-3xl p-6">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-xl font-semibold text-app-strong">{title}</h2>
        <button
          className="btn-outline btn-outline-sm"
          type="button"
          onClick={() => void onExportAll()}
        >
          {exportAllLabel}
        </button>
      </div>
      <form
        className="mt-4 flex flex-col gap-3 sm:flex-row"
        onSubmit={onCreate}
      >
        <input
          className="input flex-1"
          value={form.display_name}
          placeholder={t("resource_panel.display_name")}
          onChange={(event) =>
            setForm({ ...form, display_name: event.target.value })
          }
          required
        />
        <label className="inline-flex items-center gap-2 text-sm font-medium text-app-base">
          <input
            type="checkbox"
            checked={form.is_sharable}
            onChange={(event) =>
              setForm({ ...form, is_sharable: event.target.checked })
            }
          />
          {t("resource_panel.sharable")}
        </label>
        <button className="btn" type="submit">
          {createLabel}
        </button>
      </form>

      <div className="mt-5 space-y-3">
        {items.length === 0 ? (
          <p className="text-sm text-app-faint">{t("resource_panel.no_owned")}</p>
        ) : (
          items.map((item) => (
            <div
              key={item.id}
              className={`rounded-xl border border-app-edge bg-app-surface ${
                renderOwnedItemExtra ? "px-3 pb-2 pt-3" : "p-3"
              }`}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  {editingItemId === item.id ? (
                    <form
                      className="flex flex-wrap items-center gap-2"
                      onSubmit={(event) => void submitRename(event, item)}
                    >
                      <input
                        className="input h-8 flex-1 px-2 py-1 text-sm"
                        value={nameDraft}
                        onChange={(event) => setNameDraft(event.target.value)}
                        aria-label={t("resource_panel.edit_name_for", {
                          name: item.display_name,
                        })}
                        required
                        autoFocus
                      />
                      <button
                        className="btn-outline btn-outline-sm rounded-xl"
                        type="submit"
                        disabled={renamingItemId === item.id}
                      >
                        {t("resource_panel.save")}
                      </button>
                      <button
                        className="btn-outline btn-outline-sm rounded-xl"
                        type="button"
                        onClick={cancelEditing}
                        disabled={renamingItemId === item.id}
                      >
                        {t("resource_panel.cancel")}
                      </button>
                    </form>
                  ) : (
                    <div className="flex min-w-0 items-center gap-1">
                      <p className="truncate font-medium text-app-strong">
                        {item.display_name}
                      </p>
                        {item.is_default ? (
                          <span className="shrink-0 text-xs font-semibold text-app-faint">
                            {t("resource_panel.default")}
                          </span>
                        ) : null}
                      <button
                        className="inline-flex h-5 w-5 items-center justify-center rounded text-app-dim transition hover:text-app-accent-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        type="button"
                        onClick={() => startEditing(item)}
                        aria-label={t("resource_panel.edit_name_for", {
                          name: item.display_name,
                        })}
                        title={t("resource_panel.edit_name_for", {
                          name: item.display_name,
                        })}
                      >
                        <PencilIcon className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  )}
                  <CopyableResourceUri
                    resourceKind={resourceKind}
                    principalId={principalId}
                    resourceUri={item.uri}
                  />
                </div>
                <div className="flex items-center gap-4">
                  <button
                    className="btn-outline btn-outline-sm rounded-xl"
                    type="button"
                    onClick={() => void onExportItem(item)}
                    aria-label={t("resource_panel.export_item", {
                      name: item.display_name,
                    })}
                    title={t("resource_panel.export_item", {
                      name: item.display_name,
                    })}
                  >
                    <DownloadIcon className="h-3.5 w-3.5" />
                  </button>
                  <label className="inline-flex items-center gap-2 text-xs font-semibold text-app-base">
                    <input
                      type="checkbox"
                      checked={!!item.is_sharable}
                      onChange={(event) =>
                        onToggle(
                          item.id,
                          event.target.checked,
                          item.display_name,
                        )
                      }
                    />
                    {t("resource_panel.sharable")}
                  </label>
                </div>
              </div>
              {renderOwnedItemExtra ? (
                <div className="mt-1.5 border-t border-app-edge pt-1.5">
                  {renderOwnedItemExtra(item)}
                </div>
              ) : null}
            </div>
          ))
        )}
      </div>

      <div className="mt-6 border-t border-app-edge pt-4">
        <h3 className="text-sm font-semibold uppercase tracking-wide text-app-base">
          {t("resource_panel.shared_with_you")}
        </h3>
        <div className="mt-3 space-y-2">
          {sharedItems.length === 0 ? (
            <p className="text-sm text-app-faint">{t("resource_panel.no_shared")}</p>
          ) : (
            sharedItems.map((item) => (
              <div
                key={`${item.id}-${item.share_id}`}
                className="rounded-xl border border-app-warn-edge bg-app-warn-surface p-3"
              >
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium text-app-strong">
                      {item.display_name}
                    </p>
                    <p className="text-xs text-app-muted">
                      {t("resource_panel.owner", {
                        name: item.owner_name,
                        email: item.owner_email,
                      })}
                    </p>
                    <CopyableResourceUri
                      resourceKind={resourceKind}
                      principalId={principalId}
                      resourceUri={item.uri}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      className="btn-outline btn-outline-sm rounded-xl"
                      type="button"
                      onClick={() => void onExportItem(item)}
                      aria-label={t("resource_panel.export_item", {
                        name: item.display_name,
                      })}
                      title={t("resource_panel.export_item", {
                        name: item.display_name,
                      })}
                    >
                      <DownloadIcon className="h-3.5 w-3.5" />
                    </button>
                    <PermissionBadge permission={item.permission} />
                  </div>
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </section>
  );
}
