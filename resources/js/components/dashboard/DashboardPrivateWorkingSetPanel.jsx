import React, { useState } from "react";
import { useTranslation } from "react-i18next";

const QUICK_GUIDE_DISMISSED_STORAGE_KEY =
  "davvy-dashboard-pws-quick-guide-dismissed";

/**
 * Renders the Dashboard Private Working Set Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardPrivateWorkingSetPanel({
  privateWorkingSet,
  privateWorkingSetForm,
  privateWorkingSetIsDirty = false,
  setPrivateWorkingSetForm,
  privateWorkingSetNotice,
  savingPrivateWorkingSet,
  pullingPrivateWorkingSet,
  promotingPrivateCardId,
  dismissingSuggestionLinkId,
  privateWorkingSetPromotionHistory = [],
  contactChangeModerationEnabled,
  onSavePrivateWorkingSet,
  onPullPrivateWorkingSet,
  onPromotePrivateCard,
  onDismissSuggestedPromotion,
}) {
  const { t } = useTranslation("dashboard");
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [quickGuideDismissed, setQuickGuideDismissed] = useState(() => {
    if (typeof window === "undefined") {
      return false;
    }

    try {
      return (
        window.localStorage.getItem(QUICK_GUIDE_DISMISSED_STORAGE_KEY) === "1"
      );
    } catch {
      return false;
    }
  });
  const isPrivateWorkingSetEnabled = Boolean(privateWorkingSetForm.enabled);
  const quickGuideVisible = !quickGuideDismissed;
  const canSelectSources = isPrivateWorkingSetEnabled;
  const selectedSourceCount = Array.isArray(privateWorkingSetForm.source_ids)
    ? privateWorkingSetForm.source_ids.length
    : 0;
  const canManageSelfReviewPolicy = Boolean(
    privateWorkingSet.can_manage_self_review_policy,
  );
  const effectiveRequireReviewForSelfPromotions = Boolean(
    privateWorkingSet.effective_require_review_for_self_promotions,
  );
  const canRequireSelfReview =
    isPrivateWorkingSetEnabled &&
    contactChangeModerationEnabled &&
    canManageSelfReviewPolicy;

  const setQuickGuideVisibility = (visible) => {
    setQuickGuideDismissed(!visible);

    if (typeof window === "undefined") {
      return;
    }

    try {
      if (visible) {
        window.localStorage.removeItem(QUICK_GUIDE_DISMISSED_STORAGE_KEY);
      } else {
        window.localStorage.setItem(QUICK_GUIDE_DISMISSED_STORAGE_KEY, "1");
      }
    } catch {
      // Ignore storage failures.
    }
  };

  const toggleQuickGuide = () => setQuickGuideVisibility(quickGuideDismissed);
  const renderButtonLabelWithQualifier = (label) => {
    const value = String(label ?? "");
    const patterns = [
      { open: "(", close: ")" },
      { open: "（", close: "）" },
    ];

    for (const pattern of patterns) {
      const closeIndex = value.lastIndexOf(pattern.close);
      if (closeIndex !== value.length - 1) {
        continue;
      }

      const openIndex = value.lastIndexOf(pattern.open, closeIndex - 1);
      if (openIndex <= 0) {
        continue;
      }

      const baseLabel = value.slice(0, openIndex).trimEnd();
      const qualifier = value.slice(openIndex + 1, closeIndex).trim();
      if (baseLabel === "" || qualifier === "") {
        continue;
      }

      return (
        <>
          <span>{baseLabel}</span>
          <span className="ml-2 text-[0.85em] opacity-90">
            {pattern.open}
            {qualifier}
            {pattern.close}
          </span>
        </>
      );
    }

    return value;
  };

  return (
    <section className="surface mt-6 rounded-3xl p-6">
      <h2 className="text-xl font-semibold text-app-strong">
        {t("privateWorkingSet.title")}
      </h2>
      <div className="mt-1 text-sm text-app-muted">
        <span>{t("privateWorkingSet.subtitle")}</span>
        <button
          type="button"
          className="ml-0.5 inline-flex h-5 w-5 items-center justify-center rounded align-middle text-app-faint transition hover:text-app-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
          onClick={toggleQuickGuide}
          aria-label={
            quickGuideVisible
              ? t("privateWorkingSet.quickGuideHide")
              : t("privateWorkingSet.quickGuideShow")
          }
          title={
            quickGuideVisible
              ? t("privateWorkingSet.quickGuideHide")
              : t("privateWorkingSet.quickGuideShow")
          }
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="h-3.5 w-3.5"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5" />
            <path d="M12 8h.01" />
          </svg>
        </button>
      </div>

      {quickGuideVisible ? (
        <div className="mt-3 rounded-xl border border-app-edge bg-app-surface p-3">
          <div className="flex items-start justify-between gap-2">
            <p className="text-sm font-medium text-app-strong">
              {t("privateWorkingSet.quickGuideTitle")}
            </p>
            <button
              type="button"
              className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-app-edge text-base leading-none text-app-faint transition hover:border-app-muted hover:text-app-strong"
              onClick={() => setQuickGuideVisibility(false)}
              aria-label={t("privateWorkingSet.quickGuideClose")}
              title={t("privateWorkingSet.quickGuideClose")}
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <ol className="mt-2 space-y-1 text-xs text-app-faint">
            <li>{t("privateWorkingSet.quickGuideStep1")}</li>
            <li>{t("privateWorkingSet.quickGuideStep2")}</li>
            <li>
              {contactChangeModerationEnabled
                ? t("privateWorkingSet.quickGuideStep3Moderated")
                : t("privateWorkingSet.quickGuideStep3Direct")}
            </li>
          </ol>
        </div>
      ) : null}

      {privateWorkingSet.private_address_book_id ? (
        <p className="mt-2 text-xs text-app-faint">
          {t("privateWorkingSet.target", {
            name: privateWorkingSet.private_display_name,
            uri: privateWorkingSet.private_address_book_uri,
          })}
        </p>
      ) : null}

      <form className="mt-4 space-y-4" onSubmit={onSavePrivateWorkingSet}>
        <div className="space-y-2 rounded-xl border border-app-edge bg-app-surface p-3">
          <label className="flex items-start gap-2 text-sm font-medium text-app-base">
            <input
              type="checkbox"
              className="mt-0.5 h-4 w-4 shrink-0"
              checked={privateWorkingSetForm.enabled}
              onChange={(event) =>
                setPrivateWorkingSetForm({
                  ...privateWorkingSetForm,
                  enabled: event.target.checked,
                })
              }
            />
            <span className="min-w-0">
              <span className="block leading-5">
                {t("privateWorkingSet.enable")}
              </span>
            </span>
          </label>

          <p className="text-xs text-app-faint">
            {contactChangeModerationEnabled
              ? t("privateWorkingSet.moderationOnSummary")
              : t("privateWorkingSet.moderationOffSummary")}
          </p>

          <div className="flex justify-start">
            <button
              className="btn-outline btn-outline-sm"
              type="button"
              onClick={() => setAdvancedOpen((previous) => !previous)}
            >
              {advancedOpen
                ? t("privateWorkingSet.hideAdvanced")
                : t("privateWorkingSet.showAdvanced")}
            </button>
          </div>

          {advancedOpen ? (
            <>
              <label
                className={`flex items-start gap-2 text-sm font-medium text-app-base ${
                  privateWorkingSetForm.enabled ? "" : "opacity-60"
                }`}
                aria-disabled={!privateWorkingSetForm.enabled}
              >
                <input
                  type="checkbox"
                  className="mt-0.5 h-4 w-4 shrink-0"
                  checked={privateWorkingSetForm.hide_shared}
                  onChange={(event) =>
                    setPrivateWorkingSetForm({
                      ...privateWorkingSetForm,
                      hide_shared: event.target.checked,
                    })
                  }
                  disabled={!privateWorkingSetForm.enabled}
                />
                <span className="min-w-0">
                  <span className="block leading-5">
                    {t("privateWorkingSet.hideShared")}
                  </span>
                  <span className="block text-xs font-normal text-app-faint">
                    {t("privateWorkingSet.hideSharedHint")}
                  </span>
                </span>
              </label>

              <label
                className={`flex items-start gap-2 text-sm font-medium text-app-base ${
                  privateWorkingSetForm.enabled ? "" : "opacity-60"
                }`}
                aria-disabled={!privateWorkingSetForm.enabled}
              >
                <input
                  type="checkbox"
                  className="mt-0.5 h-4 w-4 shrink-0"
                  checked={privateWorkingSetForm.include_owned_sharable_sources}
                  onChange={(event) =>
                    setPrivateWorkingSetForm({
                      ...privateWorkingSetForm,
                      include_owned_sharable_sources: event.target.checked,
                    })
                  }
                  disabled={!privateWorkingSetForm.enabled}
                />
                <span className="min-w-0">
                  <span className="block leading-5">
                    {t("privateWorkingSet.includeOwnedSharableSources")}
                  </span>
                  <span className="block text-xs font-normal text-app-faint">
                    {t("privateWorkingSet.includeOwnedSharableSourcesHint")}
                  </span>
                </span>
              </label>

              {canManageSelfReviewPolicy ? (
                <label
                  className={`flex items-start gap-2 text-sm font-medium text-app-base ${
                    canRequireSelfReview ? "" : "opacity-60"
                  }`}
                  aria-disabled={!canRequireSelfReview}
                >
                  <input
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 shrink-0"
                    checked={
                      privateWorkingSetForm.require_review_for_self_promotions
                    }
                    onChange={(event) =>
                      setPrivateWorkingSetForm({
                        ...privateWorkingSetForm,
                        require_review_for_self_promotions:
                          event.target.checked,
                      })
                    }
                    disabled={!canRequireSelfReview}
                  />
                  <span className="min-w-0">
                    <span className="block leading-5">
                      {t("privateWorkingSet.requireReviewForSelfPromotions")}
                    </span>
                    <span className="block text-xs font-normal text-app-faint">
                      {t(
                        "privateWorkingSet.requireReviewForSelfPromotionsHint",
                      )}
                    </span>
                  </span>
                </label>
              ) : null}

              {!canManageSelfReviewPolicy ? (
                <p className="text-xs text-app-faint">
                  {contactChangeModerationEnabled
                    ? t("privateWorkingSet.nonAdminSelfReviewAlwaysQueued")
                    : t(
                        "privateWorkingSet.nonAdminSelfReviewModerationDisabled",
                      )}
                </p>
              ) : null}

              {canManageSelfReviewPolicy && !contactChangeModerationEnabled ? (
                <p className="text-xs text-app-faint">
                  {t("privateWorkingSet.requireSelfReviewModerationDisabled")}
                </p>
              ) : null}
              {canManageSelfReviewPolicy &&
              contactChangeModerationEnabled &&
              !effectiveRequireReviewForSelfPromotions ? (
                <p className="text-xs text-app-faint">
                  {t("privateWorkingSet.selfReviewPolicyDirectApply")}
                </p>
              ) : null}
            </>
          ) : (
            <p className="text-xs text-app-faint">
              {t("privateWorkingSet.advancedHiddenHint")}
            </p>
          )}
        </div>

        {isPrivateWorkingSetEnabled ? (
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm font-medium text-app-strong">
                {t("privateWorkingSet.sourcesTitle")}
              </p>
              <span className="rounded-full border border-app-edge bg-app-surface px-2 py-0.5 text-xs text-app-faint">
                {t("privateWorkingSet.selectedSourcesCount", {
                  count: selectedSourceCount,
                })}
              </span>
            </div>
            <p className="text-xs text-app-faint">
              {t("privateWorkingSet.sourcesHint")}
            </p>
            {privateWorkingSet.source_options.length === 0 ? (
              <p className="text-sm text-app-faint">
                {t("privateWorkingSet.noSources")}
              </p>
            ) : (
              <div
                className="max-h-[32rem] space-y-2 overflow-y-auto pr-1"
                data-testid="pws-source-list"
              >
                {privateWorkingSet.source_options.map((option) => {
                  const checked = privateWorkingSetForm.source_ids.includes(
                    option.id,
                  );

                  return (
                    <label
                      key={option.id}
                      className={`flex items-start gap-2 rounded-xl border border-app-edge bg-app-surface px-3 py-2 text-sm ${
                        canSelectSources ? "" : "cursor-not-allowed opacity-60"
                      }`}
                      aria-disabled={!canSelectSources}
                    >
                      <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4 shrink-0 self-start"
                        checked={checked}
                        onChange={(event) => {
                          if (event.target.checked) {
                            setPrivateWorkingSetForm({
                              ...privateWorkingSetForm,
                              source_ids: [
                                ...privateWorkingSetForm.source_ids,
                                option.id,
                              ],
                            });
                            return;
                          }

                          setPrivateWorkingSetForm({
                            ...privateWorkingSetForm,
                            source_ids: privateWorkingSetForm.source_ids.filter(
                              (id) => id !== option.id,
                            ),
                          });
                        }}
                        disabled={!canSelectSources}
                      />
                      <span className="min-w-0">
                        <span className="block font-medium text-app-strong">
                          {option.display_name}
                        </span>
                        <span className="block text-xs text-app-faint">
                          {option.scope === "owned"
                            ? t("resourcePanel.scope.owned")
                            : t("resourcePanel.scope.shared")}{" "}
                          • {option.owner_name} ({option.owner_email}) •{" "}
                          {option.can_write
                            ? t("privateWorkingSet.canWrite")
                            : t("privateWorkingSet.readOnly")}
                        </span>
                      </span>
                    </label>
                  );
                })}
              </div>
            )}
          </div>
        ) : (
          <p className="text-xs text-app-faint">
            {t("privateWorkingSet.enableToManageHint")}
          </p>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <button
            className="btn"
            type="submit"
            disabled={savingPrivateWorkingSet || !privateWorkingSetIsDirty}
          >
            {savingPrivateWorkingSet
              ? t("privateWorkingSet.saving")
              : t("privateWorkingSet.save")}
          </button>
          {isPrivateWorkingSetEnabled ? (
            <button
              className="btn btn-secondary"
              type="button"
              disabled={pullingPrivateWorkingSet}
              onClick={() => onPullPrivateWorkingSet(false)}
              title={t("privateWorkingSet.pullTooltip")}
            >
              {pullingPrivateWorkingSet
                ? t("privateWorkingSet.pulling")
                : renderButtonLabelWithQualifier(t("privateWorkingSet.pull"))}
            </button>
          ) : null}
          {advancedOpen && isPrivateWorkingSetEnabled ? (
            <>
              <button
                className="btn btn-secondary"
                type="button"
                disabled={pullingPrivateWorkingSet}
                onClick={() => onPullPrivateWorkingSet(true)}
                title={t("privateWorkingSet.forcePullTooltip")}
              >
                {pullingPrivateWorkingSet
                  ? t("privateWorkingSet.pulling")
                  : renderButtonLabelWithQualifier(
                      t("privateWorkingSet.forcePull"),
                    )}
              </button>
            </>
          ) : null}
        </div>
        <p className="text-xs text-app-faint">
          {privateWorkingSetIsDirty
            ? t("privateWorkingSet.unsavedChanges")
            : t("privateWorkingSet.noChangesToSave")}
        </p>
        {advancedOpen && isPrivateWorkingSetEnabled ? (
          <p className="text-xs text-app-faint">
            {t("privateWorkingSet.syncActionsHint")}
          </p>
        ) : null}

        {privateWorkingSetNotice ? (
          <p className="mt-2 text-sm text-app-accent" role="status">
            {privateWorkingSetNotice}
          </p>
        ) : null}

        {isPrivateWorkingSetEnabled ? (
          <>
            <div className="space-y-2">
              <p className="text-sm font-medium text-app-strong">
                {t("privateWorkingSet.promotionHistoryTitle")}
              </p>
              {(privateWorkingSetPromotionHistory ?? []).length === 0 ? (
                <p className="text-sm text-app-faint">
                  {t("privateWorkingSet.promotionHistoryEmpty")}
                </p>
              ) : (
                <div className="space-y-2">
                  {(privateWorkingSetPromotionHistory ?? []).map((row) => {
                    const timeLabel = new Date(
                      row.occurred_at,
                    ).toLocaleString();
                    const statusLabel = row.queued
                      ? t("privateWorkingSet.promotionHistoryQueued")
                      : t("privateWorkingSet.promotionHistoryApplied");

                    return (
                      <div
                        key={row.id}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-app-edge bg-app-surface px-3 py-2 text-sm"
                      >
                        <span className="min-w-0">
                          <span className="block font-medium text-app-strong">
                            {row.display_name}
                          </span>
                          {row.source_card_uri ? (
                            <span className="block text-xs text-app-faint">
                              {t("privateWorkingSet.sourceCardHint", {
                                uri: row.source_card_uri,
                              })}
                            </span>
                          ) : null}
                          <span className="block text-xs text-app-faint">
                            {t("privateWorkingSet.promotionHistoryAt", {
                              time: timeLabel,
                            })}
                          </span>
                        </span>
                        <span className="rounded-full border border-app-edge bg-app-surface px-2 py-0.5 text-xs text-app-faint">
                          {statusLabel}
                        </span>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium text-app-strong">
                {t("privateWorkingSet.suggestedTitle")}
              </p>
              <p className="text-xs text-app-faint">
                {t("privateWorkingSet.suggestedHint")}
              </p>
              {(privateWorkingSet.suggested_promotions ?? []).length === 0 ? (
                <p className="text-sm text-app-faint">
                  {t("privateWorkingSet.noSuggested")}
                </p>
              ) : (
                <div
                  className="max-h-[32rem] space-y-2 overflow-y-auto pr-1"
                  data-testid="pws-suggested-list"
                >
                  {(privateWorkingSet.suggested_promotions ?? []).map((row) => (
                    <div
                      key={row.link_id}
                      className="space-y-2 rounded-xl border border-app-edge bg-app-surface px-3 py-2 text-sm"
                    >
                      <span className="block min-w-0">
                        <span className="block font-medium text-app-strong">
                          {row.display_name}
                        </span>
                        <span className="block text-xs text-app-faint">
                          {t("privateWorkingSet.sourceCardHint", {
                            uri: row.source_card_uri,
                          })}
                        </span>
                        <span className="block text-xs text-app-faint">
                          {t("privateWorkingSet.suggestedFieldsHint", {
                            fields: (row.suggested_fields ?? []).join(", "),
                          })}
                        </span>
                      </span>
                      <div className="flex flex-wrap items-center gap-2">
                        <button
                          className="btn btn-secondary"
                          type="button"
                          disabled={
                            promotingPrivateCardId === row.private_card_id
                          }
                          onClick={() =>
                            onPromotePrivateCard(row.private_card_id)
                          }
                        >
                          {promotingPrivateCardId === row.private_card_id
                            ? t("privateWorkingSet.promoting")
                            : t("privateWorkingSet.promote")}
                        </button>
                        <button
                          className="btn btn-secondary"
                          type="button"
                          disabled={dismissingSuggestionLinkId === row.link_id}
                          onClick={() =>
                            onDismissSuggestedPromotion(row.link_id)
                          }
                        >
                          {dismissingSuggestionLinkId === row.link_id
                            ? t("privateWorkingSet.dismissing")
                            : t("privateWorkingSet.dismiss")}
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium text-app-strong">
                {t("privateWorkingSet.linkedCardsTitle")}
              </p>
              <p className="text-xs text-app-faint">
                {t("privateWorkingSet.linkedCardsHint")}
              </p>
              {(privateWorkingSet.linked_cards ?? []).length === 0 ? (
                <p className="text-sm text-app-faint">
                  {t("privateWorkingSet.noLinkedCards")}
                </p>
              ) : (
                <div
                  className="max-h-[32rem] space-y-2 overflow-y-auto pr-1"
                  data-testid="pws-linked-list"
                >
                  {(privateWorkingSet.linked_cards ?? []).map((row) => (
                    <div
                      key={row.private_card_id}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-app-edge bg-app-surface px-3 py-2 text-sm"
                    >
                      <span className="min-w-0">
                        <span className="block font-medium text-app-strong">
                          {row.display_name}
                        </span>
                        <span className="block text-xs text-app-faint">
                          {t("privateWorkingSet.sourceCardHint", {
                            uri: row.source_card_uri,
                          })}
                        </span>
                      </span>
                      <button
                        className="btn btn-secondary"
                        type="button"
                        disabled={
                          promotingPrivateCardId === row.private_card_id
                        }
                        onClick={() =>
                          onPromotePrivateCard(row.private_card_id)
                        }
                      >
                        {promotingPrivateCardId === row.private_card_id
                          ? t("privateWorkingSet.promoting")
                          : t("privateWorkingSet.promote")}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        ) : null}
      </form>
    </section>
  );
}
