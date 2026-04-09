import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Dashboard Private Working Set Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardPrivateWorkingSetPanel({
  privateWorkingSet,
  privateWorkingSetForm,
  setPrivateWorkingSetForm,
  privateWorkingSetNotice,
  savingPrivateWorkingSet,
  pullingPrivateWorkingSet,
  promotingPrivateCardId,
  dismissingSuggestionLinkId,
  onSavePrivateWorkingSet,
  onPullPrivateWorkingSet,
  onPromotePrivateCard,
  onDismissSuggestedPromotion,
}) {
  const { t } = useTranslation("dashboard");
  const canSelectSources = privateWorkingSetForm.enabled;

  return (
    <section className="surface mt-6 rounded-3xl p-6">
      <h2 className="text-xl font-semibold text-app-strong">
        {t("privateWorkingSet.title")}
      </h2>
      <p className="mt-1 text-sm text-app-muted">
        {t("privateWorkingSet.subtitle")}
      </p>

      {privateWorkingSet.private_address_book_id ? (
        <p className="mt-2 text-xs text-app-faint">
          {t("privateWorkingSet.target", {
            name: privateWorkingSet.private_display_name,
            uri: privateWorkingSet.private_address_book_uri,
          })}
        </p>
      ) : null}

      <form className="mt-4 space-y-4" onSubmit={onSavePrivateWorkingSet}>
        <label className="inline-flex items-center gap-2 text-sm font-medium text-app-base">
          <input
            type="checkbox"
            checked={privateWorkingSetForm.enabled}
            onChange={(event) =>
              setPrivateWorkingSetForm({
                ...privateWorkingSetForm,
                enabled: event.target.checked,
              })
            }
          />
          {t("privateWorkingSet.enable")}
        </label>

        <label className="inline-flex items-center gap-2 text-sm font-medium text-app-base">
          <input
            type="checkbox"
            checked={privateWorkingSetForm.hide_shared}
            onChange={(event) =>
              setPrivateWorkingSetForm({
                ...privateWorkingSetForm,
                hide_shared: event.target.checked,
              })
            }
            disabled={!privateWorkingSetForm.enabled}
          />
          {t("privateWorkingSet.hideShared")}
        </label>

        <div className="space-y-2">
          <p className="text-sm font-medium text-app-strong">
            {t("privateWorkingSet.sourcesTitle")}
          </p>
          {privateWorkingSet.source_options.length === 0 ? (
            <p className="text-sm text-app-faint">
              {t("privateWorkingSet.noSources")}
            </p>
          ) : (
            privateWorkingSet.source_options.map((option) => {
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
                      {option.owner_name} ({option.owner_email}) •{" "}
                      {option.can_write
                        ? t("privateWorkingSet.canWrite")
                        : t("privateWorkingSet.readOnly")}
                    </span>
                  </span>
                </label>
              );
            })
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            className="btn"
            type="submit"
            disabled={savingPrivateWorkingSet}
          >
            {savingPrivateWorkingSet
              ? t("privateWorkingSet.saving")
              : t("privateWorkingSet.save")}
          </button>
          <button
            className="btn btn-secondary"
            type="button"
            disabled={pullingPrivateWorkingSet || !privateWorkingSetForm.enabled}
            onClick={() => onPullPrivateWorkingSet(false)}
          >
            {pullingPrivateWorkingSet
              ? t("privateWorkingSet.pulling")
              : t("privateWorkingSet.pull")}
          </button>
          <button
            className="btn btn-secondary"
            type="button"
            disabled={pullingPrivateWorkingSet || !privateWorkingSetForm.enabled}
            onClick={() => onPullPrivateWorkingSet(true)}
          >
            {pullingPrivateWorkingSet
              ? t("privateWorkingSet.pulling")
              : t("privateWorkingSet.forcePull")}
          </button>
        </div>

        {privateWorkingSetNotice ? (
          <p className="mt-2 text-sm text-app-accent" role="status">
            {privateWorkingSetNotice}
          </p>
        ) : null}

        <div className="space-y-2">
          <p className="text-sm font-medium text-app-strong">
            {t("privateWorkingSet.suggestedTitle")}
          </p>
          {(privateWorkingSet.suggested_promotions ?? []).length === 0 ? (
            <p className="text-sm text-app-faint">
              {t("privateWorkingSet.noSuggested")}
            </p>
          ) : (
            (privateWorkingSet.suggested_promotions ?? []).map((row) => (
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
                      !privateWorkingSetForm.enabled ||
                      promotingPrivateCardId === row.private_card_id
                    }
                    onClick={() => onPromotePrivateCard(row.private_card_id)}
                  >
                    {promotingPrivateCardId === row.private_card_id
                      ? t("privateWorkingSet.promoting")
                      : t("privateWorkingSet.promote")}
                  </button>
                  <button
                    className="btn btn-secondary"
                    type="button"
                    disabled={
                      !privateWorkingSetForm.enabled ||
                      dismissingSuggestionLinkId === row.link_id
                    }
                    onClick={() => onDismissSuggestedPromotion(row.link_id)}
                  >
                    {dismissingSuggestionLinkId === row.link_id
                      ? t("privateWorkingSet.dismissing")
                      : t("privateWorkingSet.dismiss")}
                  </button>
                </div>
              </div>
            ))
          )}
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-app-strong">
            {t("privateWorkingSet.linkedCardsTitle")}
          </p>
          {(privateWorkingSet.linked_cards ?? []).length === 0 ? (
            <p className="text-sm text-app-faint">
              {t("privateWorkingSet.noLinkedCards")}
            </p>
          ) : (
            (privateWorkingSet.linked_cards ?? []).map((row) => (
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
                    !privateWorkingSetForm.enabled ||
                    promotingPrivateCardId === row.private_card_id
                  }
                  onClick={() => onPromotePrivateCard(row.private_card_id)}
                >
                  {promotingPrivateCardId === row.private_card_id
                    ? t("privateWorkingSet.promoting")
                    : t("privateWorkingSet.promote")}
                </button>
              </div>
            ))
          )}
        </div>
      </form>
    </section>
  );
}
