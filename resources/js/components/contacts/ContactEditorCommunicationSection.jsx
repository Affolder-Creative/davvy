import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Contact Editor Communication Section component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactEditorCommunicationSection({
  isOpen,
  onToggle,
  form,
  updateFormField,
  labelOptions,
  isOptionalFieldVisible,
  LabeledValueEditor,
  AddressEditor,
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
            {t("editor.communication_section.label")}
          </span>
          <span className="block text-xs text-app-faint">
            {t("editor.communication_section.description")}
          </span>
        </span>
        <span className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-app-edge text-xs text-app-faint">
          {isOpen ? "-" : "+"}
        </span>
      </button>

      {isOpen ? (
        <div className="mt-3 space-y-4 px-1 pb-1">
          <LabeledValueEditor
            title={t("editor.communication_section.field.phone.title")}
            rows={form.phones}
            setRows={(rows) => updateFormField("phones", rows)}
            labelOptions={labelOptions.phones}
            valuePlaceholder={t(
              "editor.communication_section.field.phone.placeholder",
            )}
            addLabel={t("editor.communication_section.field.phone.add_label")}
          />
          <LabeledValueEditor
            title={t("editor.communication_section.field.email.title")}
            rows={form.emails}
            setRows={(rows) => updateFormField("emails", rows)}
            labelOptions={labelOptions.emails}
            valuePlaceholder={t(
              "editor.communication_section.field.email.placeholder",
            )}
            addLabel={t("editor.communication_section.field.email.add_label")}
          />
          <AddressEditor
            rows={form.addresses}
            setRows={(rows) => updateFormField("addresses", rows)}
            labelOptions={labelOptions.addresses}
          />
          <LabeledValueEditor
            title={t("editor.communication_section.field.url.title")}
            rows={form.urls}
            setRows={(rows) => updateFormField("urls", rows)}
            labelOptions={labelOptions.urls}
            valuePlaceholder={t(
              "editor.communication_section.field.url.placeholder",
            )}
            addLabel={t("editor.communication_section.field.url.add_label")}
          />
          {isOptionalFieldVisible("instant_messages") ? (
            <LabeledValueEditor
              title={t(
                "editor.communication_section.field.instant_message.title",
              )}
              rows={form.instant_messages}
              setRows={(rows) => updateFormField("instant_messages", rows)}
              labelOptions={labelOptions.instant_messages}
              valuePlaceholder={t(
                "editor.communication_section.field.instant_message.placeholder",
              )}
              addLabel={t(
                "editor.communication_section.field.instant_message.add_label",
              )}
            />
          ) : null}
        </div>
      ) : null}
    </section>
  );
}
