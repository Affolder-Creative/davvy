import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Contact Editor Personal Section component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactEditorPersonalSection({
  isOpen,
  onToggle,
  form,
  Field,
  isOptionalFieldVisible,
  updateFormField,
  PRONOUN_OPTIONS,
  showOptionalField,
  updateBirthdayField,
  updateDeathDateField,
  DateEditor,
  labelOptions,
  RelatedNameEditor,
  CategoryTagEditor,
  categoryOptions,
  relatedNameOptions,
  setForm,
}) {
  const { t } = useTranslation("contacts");
  const CategoryTagsComponent =
    typeof CategoryTagEditor === "function" ? CategoryTagEditor : null;
  return (
    <section className="rounded-2xl border border-app-edge bg-app-surface p-3">
      <button
        className="flex w-full items-center justify-between gap-3 rounded-xl px-2 py-1 text-left"
        type="button"
        onClick={onToggle}
        aria-expanded={isOpen}
      >
        <span>
          <span className="block text-sm font-semibold uppercase tracking-wide text-app-base">
            {t("editor.personalSection.label")}
          </span>
          <span className="block text-xs text-app-faint">
            {t("editor.personalSection.description")}
          </span>
        </span>
        <span className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-app-edge text-xs text-app-faint">
          {isOpen ? "-" : "+"}
        </span>
      </button>

      {isOpen ? (
        <div className="mt-3 space-y-4 px-1 pb-1">
          <div className="grid gap-3 md:grid-cols-2">
            <Field label={t("editor.personalSection.field.pronouns")}>
              <select
                className="input"
                value={form.pronouns}
                onChange={(event) => {
                  const nextValue = event.target.value;
                  updateFormField("pronouns", nextValue);

                  if (nextValue === "custom") {
                    showOptionalField("pronouns_custom");
                  }
                }}
              >
                {PRONOUN_OPTIONS.map((option) => (
                  <option key={option.value || "none"} value={option.value}>
                    {t(option.labelKey, { defaultValue: option.fallback })}
                  </option>
                ))}
              </select>
            </Field>
            {isOptionalFieldVisible("pronouns_custom") ? (
              <Field label={t("editor.personalSection.field.pronounsCustom")}>
                <input
                  className="input"
                  value={form.pronouns_custom}
                  onChange={(event) =>
                    updateFormField("pronouns_custom", event.target.value)
                  }
                  placeholder={t(
                    "editor.personalSection.field.pronounsCustomPlaceholder",
                  )}
                  disabled={form.pronouns !== "custom" && !form.pronouns_custom}
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("ringtone") ? (
              <Field label={t("editor.personalSection.field.ringtone")}>
                <input
                  className="input"
                  value={form.ringtone}
                  onChange={(event) =>
                    updateFormField("ringtone", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("text_tone") ? (
              <Field label={t("editor.personalSection.field.textTone")}>
                <input
                  className="input"
                  value={form.text_tone}
                  onChange={(event) =>
                    updateFormField("text_tone", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("verification_code") ? (
              <Field
                label={t("editor.personalSection.field.verificationCode")}
              >
                <input
                  className="input"
                  value={form.verification_code}
                  onChange={(event) =>
                    updateFormField("verification_code", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("profile") ? (
              <Field label={t("editor.personalSection.field.profile")}>
                <input
                  className="input"
                  value={form.profile}
                  onChange={(event) =>
                    updateFormField("profile", event.target.value)
                  }
                />
              </Field>
            ) : null}
          </div>

          <section className="rounded-2xl border border-app-accent-edge bg-app-surface p-3 ring-1 ring-teal-500/10">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-app-accent">
              {t("editor.personalSection.field.householdLabel")}
            </p>
            <label className="inline-flex items-center gap-2 text-[13px] font-semibold leading-5 text-app-base">
              <input
                type="checkbox"
                checked={!!form.head_of_household}
                onChange={(event) =>
                  updateFormField("head_of_household", event.target.checked)
                }
              />
              {t("editor.personalSection.field.headOfHousehold")}
            </label>
          </section>

          <section className="rounded-2xl border border-app-accent-edge bg-app-surface p-4 ring-1 ring-teal-500/10">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-app-accent">
                {t("editor.personalSection.milestonesDates.label")}
              </h3>
              <span className="text-xs text-app-faint">
                {t("editor.personalSection.milestonesDates.description")}
              </span>
            </div>
            <div className="mt-3 space-y-3">
              <section className="rounded-2xl border border-app-edge bg-app-surface p-4">
                <h3 className="text-sm font-semibold uppercase tracking-wide text-app-base">
                  {t("editor.personalSection.milestonesDates.birthday")}
                </h3>
                <div className="mt-3 grid gap-3 md:grid-cols-3">
                  <Field
                    label={t("editor.personalSection.milestonesDates.month")}
                  >
                    <input
                      className="input"
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={2}
                      placeholder={t(
                        "editor.personalSection.milestonesDates.monthPlaceholder",
                      )}
                      value={form.birthday.month}
                      onChange={(event) =>
                        updateBirthdayField("month", event.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label={t("editor.personalSection.milestonesDates.day")}
                  >
                    <input
                      className="input"
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={2}
                      placeholder={t(
                        "editor.personalSection.milestonesDates.dayPlaceholder",
                      )}
                      value={form.birthday.day}
                      onChange={(event) =>
                        updateBirthdayField("day", event.target.value)
                      }
                    />
                  </Field>
                  <Field
                    label={t("editor.personalSection.milestonesDates.year")}
                  >
                    <input
                      className="input"
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={4}
                      placeholder={t(
                        "editor.personalSection.milestonesDates.yearPlaceholder",
                      )}
                      value={form.birthday.year}
                      onChange={(event) =>
                        updateBirthdayField("year", event.target.value)
                      }
                    />
                  </Field>
                </div>
              </section>

              {isOptionalFieldVisible("death_date") ? (
                <section className="rounded-2xl border border-app-edge bg-app-surface p-4">
                  <h3 className="text-sm font-semibold uppercase tracking-wide text-app-base">
                    {t("editor.personalSection.milestonesDates.deathDate")}
                  </h3>
                  <div className="mt-3 grid gap-3 md:grid-cols-3">
                    <Field
                      label={t("editor.personalSection.milestonesDates.month")}
                    >
                      <input
                        className="input"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={2}
                        placeholder={t(
                          "editor.personalSection.milestonesDates.monthPlaceholder",
                        )}
                        value={form.death_date?.month ?? ""}
                        onChange={(event) =>
                          updateDeathDateField("month", event.target.value)
                        }
                      />
                    </Field>
                    <Field
                      label={t("editor.personalSection.milestonesDates.day")}
                    >
                      <input
                        className="input"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={2}
                        placeholder={t(
                          "editor.personalSection.milestonesDates.dayPlaceholder",
                        )}
                        value={form.death_date?.day ?? ""}
                        onChange={(event) =>
                          updateDeathDateField("day", event.target.value)
                        }
                      />
                    </Field>
                    <Field
                      label={t("editor.personalSection.milestonesDates.year")}
                    >
                      <input
                        className="input"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={4}
                        placeholder={t(
                          "editor.personalSection.milestonesDates.yearPlaceholder",
                        )}
                        value={form.death_date?.year ?? ""}
                        onChange={(event) =>
                          updateDeathDateField("year", event.target.value)
                        }
                      />
                    </Field>
                  </div>
                </section>
              ) : null}

              {isOptionalFieldVisible("dates") ? (
                <DateEditor
                  rows={form.dates}
                  setRows={(rows) => updateFormField("dates", rows)}
                  labelOptions={labelOptions.dates}
                />
              ) : null}

              <section className="rounded-2xl bg-app-surface pt-2 px-3">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-app-base">
                  {t(
                    "editor.personalSection.milestonesDates.calendarBehavior",
                  )}
                </p>
                <label className="inline-flex items-center gap-2 text-[13px] font-semibold leading-5 text-app-base">
                  <input
                    type="checkbox"
                    checked={!!form.exclude_milestone_calendars}
                    onChange={(event) =>
                      updateFormField(
                        "exclude_milestone_calendars",
                        event.target.checked,
                      )
                    }
                  />
                  {t(
                    "editor.personalSection.milestonesDates.excludeFromMilestoneCalendars",
                  )}
                </label>
                <p className="mt-1.5 text-[11px] text-app-faint">
                  {t(
                    "editor.personalSection.milestonesDates.excludeFromMilestoneCalendarsDescription",
                  )}
                </p>
              </section>
            </div>
          </section>

          <RelatedNameEditor
            rows={form.related_names}
            setRows={(nextRowsOrUpdater) =>
              setForm((previousForm) => {
                const currentRows = Array.isArray(previousForm.related_names)
                  ? previousForm.related_names
                  : [];
                const nextRows =
                  typeof nextRowsOrUpdater === "function"
                    ? nextRowsOrUpdater(currentRows)
                    : nextRowsOrUpdater;

                return {
                  ...previousForm,
                  related_names: nextRows,
                };
              })
            }
            contactOptions={relatedNameOptions}
            labelOptions={labelOptions.related_names}
          />

          {CategoryTagsComponent ? (
            <CategoryTagsComponent
              categories={Array.isArray(form.categories) ? form.categories : []}
              onChange={(categories) => updateFormField("categories", categories)}
              suggestions={Array.isArray(categoryOptions) ? categoryOptions : []}
            />
          ) : null}
        </div>
      ) : null}
    </section>
  );
}
