import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Dashboard Apple Compat Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardAppleCompatPanel({
  appleCompat,
  appleCompatForm,
  setAppleCompatForm,
  canSelectAppleCompatSources,
  onSaveAppleCompat,
}) {
  const { t } = useTranslation("dashboard");

  return (
    <section className="surface mt-6 rounded-3xl p-6">
      <h2 className="text-xl font-semibold text-app-strong">
        {t("apple_compat.title")}
      </h2>
      <p className="mt-1 text-sm text-app-muted">
        {t("apple_compat.subtitle", {
          target: appleCompat.target_display_name,
        })}{" "}
        (<code>{appleCompat.target_display_name}</code>)
      </p>

      {appleCompat.target_address_book_id ? (
        <p className="mt-2 text-xs text-app-faint">
          {t("apple_compat.target", {
            name: appleCompat.target_display_name,
            uri: appleCompat.target_address_book_uri,
          })}
        </p>
      ) : (
        <p className="mt-2 text-xs text-app-danger">
          {t("apple_compat.missing_target")}
        </p>
      )}

      <form className="mt-4 space-y-4" onSubmit={onSaveAppleCompat}>
        <label className="inline-flex items-center gap-2 text-sm font-medium text-app-base">
          <input
            type="checkbox"
            checked={appleCompatForm.enabled}
            onChange={(event) =>
              setAppleCompatForm({
                ...appleCompatForm,
                enabled: event.target.checked,
              })
            }
            disabled={!appleCompat.target_address_book_id}
          />
          {t("apple_compat.enable")}
        </label>

        <div className="space-y-2">
          <p className="text-sm font-medium text-app-strong">
            {t("apple_compat.sources_title")}
          </p>
          {appleCompat.source_options.length === 0 ? (
            <p className="text-sm text-app-faint">
              {t("apple_compat.no_sources")}
            </p>
          ) : (
            appleCompat.source_options.map((option) => {
              const checked = appleCompatForm.source_ids.includes(option.id);

              return (
                <label
                  key={option.id}
                  className={`flex items-start gap-2 rounded-xl border border-app-edge bg-app-surface px-3 py-2 text-sm ${
                    canSelectAppleCompatSources
                      ? ""
                      : "cursor-not-allowed opacity-60"
                  }`}
                  aria-disabled={!canSelectAppleCompatSources}
                >
                  <input
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 shrink-0 self-start"
                    checked={checked}
                    onChange={(event) => {
                      if (event.target.checked) {
                        setAppleCompatForm({
                          ...appleCompatForm,
                          source_ids: [...appleCompatForm.source_ids, option.id],
                        });
                        return;
                      }

                      setAppleCompatForm({
                        ...appleCompatForm,
                        source_ids: appleCompatForm.source_ids.filter(
                          (id) => id !== option.id,
                        ),
                      });
                    }}
                    disabled={!canSelectAppleCompatSources}
                  />
                  <span className="min-w-0">
                    <span className="block font-medium text-app-strong">
                      {option.display_name}
                    </span>
                    <span className="block text-xs text-app-faint">
                      {option.scope === "owned"
                        ? t("resource_panel.scope.owned")
                        : t("resource_panel.scope.shared")}{" "}
                      •{" "}
                      {option.owner_name} ({option.owner_email})
                    </span>
                  </span>
                </label>
              );
            })
          )}
        </div>

        <div>
          <button
            className="btn"
            type="submit"
            disabled={!appleCompat.target_address_book_id}
          >
            {t("apple_compat.save")}
          </button>
        </div>
      </form>
    </section>
  );
}
