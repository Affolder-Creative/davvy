import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Contact Editor Name Section component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactEditorNameSection({
  isOpen,
  onToggle,
  form,
  Field,
  isOptionalFieldVisible,
  updateFormField,
}) {
  const { t } = useTranslation("contacts");
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
            {t("editor.nameSection.label")}
          </span>
          <span className="block text-xs text-app-faint">
            {t("editor.nameSection.description")}
          </span>
        </span>
        <span className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-app-edge text-xs text-app-faint">
          {isOpen ? "-" : "+"}
        </span>
      </button>

      {isOpen ? (
        <div className="mt-3 px-1 pb-1">
          <div className="grid gap-3 md:grid-cols-3">
            {isOptionalFieldVisible("prefix") ? (
              <Field label={t("editor.nameSection.field.prefix")}>
                <input
                  className="input"
                  value={form.prefix}
                  onChange={(event) =>
                    updateFormField("prefix", event.target.value)
                  }
                />
              </Field>
            ) : null}
            <Field label={t("editor.nameSection.field.first")} required>
              <input
                className="input"
                value={form.first_name}
                onChange={(event) =>
                  updateFormField("first_name", event.target.value)
                }
              />
            </Field>
            {isOptionalFieldVisible("middle_name") ? (
              <Field label={t("editor.nameSection.field.middle")}>
                <input
                  className="input"
                  value={form.middle_name}
                  onChange={(event) =>
                    updateFormField("middle_name", event.target.value)
                  }
                />
              </Field>
            ) : null}
            <Field label={t("editor.nameSection.field.last")} required>
              <input
                className="input"
                value={form.last_name}
                onChange={(event) =>
                  updateFormField("last_name", event.target.value)
                }
              />
            </Field>
            {isOptionalFieldVisible("suffix") ? (
              <Field label={t("editor.nameSection.field.suffix")}>
                <input
                  className="input"
                  value={form.suffix}
                  onChange={(event) =>
                    updateFormField("suffix", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("nickname") ? (
              <Field label={t("editor.nameSection.field.nickname")}>
                <input
                  className="input"
                  value={form.nickname}
                  onChange={(event) =>
                    updateFormField("nickname", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("maiden_name") ? (
              <Field label={t("editor.nameSection.field.maidenName")}>
                <input
                  className="input"
                  value={form.maiden_name}
                  onChange={(event) =>
                    updateFormField("maiden_name", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("phonetic_first_name") ? (
              <Field label={t("editor.nameSection.field.phoneticFirstName")}>
                <input
                  className="input"
                  value={form.phonetic_first_name}
                  onChange={(event) =>
                    updateFormField("phonetic_first_name", event.target.value)
                  }
                />
              </Field>
            ) : null}
            {isOptionalFieldVisible("phonetic_last_name") ? (
              <Field label={t("editor.nameSection.field.phoneticLastName")}>
                <input
                  className="input"
                  value={form.phonetic_last_name}
                  onChange={(event) =>
                    updateFormField("phonetic_last_name", event.target.value)
                  }
                />
              </Field>
            ) : null}
          </div>
        </div>
      ) : null}
    </section>
  );
}
