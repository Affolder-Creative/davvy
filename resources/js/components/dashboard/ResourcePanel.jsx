import React, { useEffect, useRef, useState } from "react";
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
  onDelete = null,
  renderOwnedItemExtra = null,
  CopyableResourceUri,
  PermissionBadge,
  DownloadIcon,
  PencilIcon,
  TrashIcon = null,
}) {
  const { t } = useTranslation("dashboard");
  const [editingItemId, setEditingItemId] = useState(null);
  const [nameDraft, setNameDraft] = useState("");
  const [renamingItemId, setRenamingItemId] = useState(null);
  const [deletingItemId, setDeletingItemId] = useState(null);
  const [mobileActionsItemId, setMobileActionsItemId] = useState(null);
  const mobileActionsRef = useRef(null);

  const closeMobileActions = () => {
    setMobileActionsItemId(null);
  };

  useEffect(() => {
    if (mobileActionsItemId === null) {
      return undefined;
    }

    const closeOnOutsidePointerDown = (event) => {
      if (
        mobileActionsRef.current &&
        !mobileActionsRef.current.contains(event.target)
      ) {
        closeMobileActions();
      }
    };

    window.addEventListener("pointerdown", closeOnOutsidePointerDown);

    return () => {
      window.removeEventListener("pointerdown", closeOnOutsidePointerDown);
    };
  }, [mobileActionsItemId]);

  const startEditing = (item) => {
    closeMobileActions();
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

  const submitDelete = async (item) => {
    if (!onDelete || item.is_default) {
      return;
    }

    closeMobileActions();
    setDeletingItemId(item.id);
    try {
      await onDelete(item);
    } catch {
      // Errors are surfaced by DashboardPage.
    } finally {
      setDeletingItemId(null);
    }
  };

  return (
    <section className="surface rounded-3xl p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-xl font-semibold text-app-strong">{title}</h2>
        <button
          className="btn-outline btn-outline-sm w-full sm:w-auto"
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
          placeholder={t("resourcePanel.displayName")}
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
          {t("resourcePanel.sharable")}
        </label>
        <button className="btn" type="submit">
          {createLabel}
        </button>
      </form>

      <div className="mt-5 space-y-3">
        {items.length === 0 ? (
          <p className="text-sm text-app-faint">{t("resourcePanel.noOwned")}</p>
        ) : (
          items.map((item) => (
            <div
              key={item.id}
              className={`rounded-xl border border-app-edge bg-app-surface ${
                renderOwnedItemExtra ? "px-3 pb-2 pt-3" : "p-3"
              }`}
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
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
                        aria-label={t("resourcePanel.editNameFor", {
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
                        {t("resourcePanel.save")}
                      </button>
                      <button
                        className="btn-outline btn-outline-sm rounded-xl"
                        type="button"
                        onClick={cancelEditing}
                        disabled={renamingItemId === item.id}
                      >
                        {t("resourcePanel.cancel")}
                      </button>
                    </form>
                  ) : (
                    <div className="flex min-w-0 items-center gap-1">
                      <p className="truncate font-medium text-app-strong">
                        {item.display_name}
                      </p>
                      {item.is_default ? (
                        <span className="shrink-0 text-xs font-semibold text-app-faint">
                          {t("resourcePanel.default")}
                        </span>
                      ) : null}
                      <div className="hidden items-center gap-1 sm:flex">
                        <button
                          className="inline-flex h-5 w-5 items-center justify-center rounded text-app-dim transition hover:text-app-accent-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                          type="button"
                          onClick={() => startEditing(item)}
                          aria-label={t("resourcePanel.editNameFor", {
                            name: item.display_name,
                          })}
                          title={t("resourcePanel.editNameFor", {
                            name: item.display_name,
                          })}
                        >
                          <PencilIcon className="h-3.5 w-3.5" />
                        </button>
                        {!item.is_default && onDelete && TrashIcon ? (
                          <button
                            className="inline-flex h-5 w-5 items-center justify-center rounded text-app-dim transition hover:text-app-danger focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-50"
                            type="button"
                            onClick={() => void submitDelete(item)}
                            disabled={deletingItemId === item.id}
                            aria-label={t("resourcePanel.deleteResource", {
                              name: item.display_name,
                            })}
                            title={t("resourcePanel.deleteResource", {
                              name: item.display_name,
                            })}
                          >
                            <TrashIcon className="h-3.5 w-3.5" />
                          </button>
                        ) : null}
                      </div>
                    </div>
                  )}
                  <CopyableResourceUri
                    resourceKind={resourceKind}
                    principalId={principalId}
                    resourceUri={item.uri}
                  />
                </div>
                <div className="hidden items-center gap-4 sm:flex">
                  <button
                    className="btn-outline btn-outline-sm rounded-xl"
                    type="button"
                    onClick={() => void onExportItem(item)}
                    aria-label={t("resourcePanel.exportItem", {
                      name: item.display_name,
                    })}
                    title={t("resourcePanel.exportItem", {
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
                    {t("resourcePanel.sharable")}
                  </label>
                </div>
                {editingItemId !== item.id ? (
                  <div
                    className="relative sm:hidden"
                    ref={mobileActionsItemId === item.id ? mobileActionsRef : null}
                  >
                    <button
                      className="btn-outline btn-outline-sm rounded-xl !px-2.5"
                      type="button"
                      onClick={() =>
                        setMobileActionsItemId((current) =>
                          current === item.id ? null : item.id,
                        )
                      }
                      aria-expanded={mobileActionsItemId === item.id}
                      aria-label={t("resourcePanel.actionsFor", {
                        name: item.display_name,
                      })}
                      title={t("resourcePanel.actionsFor", {
                        name: item.display_name,
                      })}
                    >
                      <svg
                        aria-hidden="true"
                        className="h-4 w-4"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                      >
                        <circle cx="5" cy="12" r="1.75" />
                        <circle cx="12" cy="12" r="1.75" />
                        <circle cx="19" cy="12" r="1.75" />
                      </svg>
                    </button>
                    {mobileActionsItemId === item.id ? (
                      <div className="absolute right-0 top-full z-20 mt-2 w-40 rounded-xl border border-app-edge bg-app-surface p-1.5 shadow-lg backdrop-blur">
                        <button
                          className="btn-outline btn-outline-sm w-full justify-start gap-2 rounded-lg !px-2.5 !py-1.5"
                          type="button"
                          onClick={() => {
                            closeMobileActions();
                            void onExportItem(item);
                          }}
                          aria-label={t("resourcePanel.exportItem", {
                            name: item.display_name,
                          })}
                          title={t("resourcePanel.exportItem", {
                            name: item.display_name,
                          })}
                        >
                          <DownloadIcon className="h-3.5 w-3.5" />
                          <span>{t("resourcePanel.export")}</span>
                        </button>
                        <button
                          className="btn-outline btn-outline-sm mt-1 w-full justify-start gap-2 rounded-lg !px-2.5 !py-1.5"
                          type="button"
                          onClick={() => startEditing(item)}
                          aria-label={t("resourcePanel.editNameFor", {
                            name: item.display_name,
                          })}
                          title={t("resourcePanel.editNameFor", {
                            name: item.display_name,
                          })}
                        >
                          <PencilIcon className="h-3.5 w-3.5" />
                          <span>{t("resourcePanel.rename")}</span>
                        </button>
                        {!item.is_default && onDelete && TrashIcon ? (
                          <button
                            className="btn-outline btn-outline-sm mt-1 w-full justify-start gap-2 rounded-lg !px-2.5 !py-1.5 text-app-danger"
                            type="button"
                            onClick={() => void submitDelete(item)}
                            disabled={deletingItemId === item.id}
                            aria-label={t("resourcePanel.deleteResource", {
                              name: item.display_name,
                            })}
                            title={t("resourcePanel.deleteResource", {
                              name: item.display_name,
                            })}
                          >
                            <TrashIcon className="h-3.5 w-3.5" />
                            <span>{t("resourcePanel.delete")}</span>
                          </button>
                        ) : null}
                        <label className="mt-1 flex items-center justify-between gap-2 rounded-lg border border-app-edge bg-app-surface px-2.5 py-1.5 text-xs font-semibold text-app-base">
                          <span>{t("resourcePanel.sharable")}</span>
                          <input
                            type="checkbox"
                            checked={!!item.is_sharable}
                            onChange={(event) => {
                              onToggle(
                                item.id,
                                event.target.checked,
                                item.display_name,
                              );
                              closeMobileActions();
                            }}
                          />
                        </label>
                      </div>
                    ) : null}
                  </div>
                ) : null}
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
          {t("resourcePanel.sharedWithYou")}
        </h3>
        <div className="mt-3 space-y-2">
          {sharedItems.length === 0 ? (
            <p className="text-sm text-app-faint">{t("resourcePanel.noShared")}</p>
          ) : (
            sharedItems.map((item) => (
              <div
                key={`${item.id}-${item.share_id}`}
                className="rounded-xl border border-app-warn-edge bg-app-warn-surface p-3"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-app-strong">
                      {item.display_name}
                    </p>
                    <p className="text-xs text-app-muted">
                      {t("resourcePanel.owner", {
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
                  <div className="flex w-full items-center justify-end gap-2 sm:w-auto">
                    <button
                      className="btn-outline btn-outline-sm rounded-xl"
                      type="button"
                      onClick={() => void onExportItem(item)}
                      aria-label={t("resourcePanel.exportItem", {
                        name: item.display_name,
                      })}
                      title={t("resourcePanel.exportItem", {
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
