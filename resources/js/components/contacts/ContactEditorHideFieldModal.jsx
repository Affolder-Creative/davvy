import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Contact Editor Hide Field Modal.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactEditorHideFieldModal({
  pendingHideFieldId,
  pendingHideFieldLabel,
  onCancel,
  onResolve,
}) {
  const { t } = useTranslation("contacts");
  if (!pendingHideFieldId) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="surface w-full max-w-md rounded-2xl p-5">
        <h3 className="text-base font-semibold text-app-strong">
          {t("editor.hide_field_modal.title", { pendingHideFieldLabel })}
        </h3>
        <p className="mt-2 text-sm text-app-muted">
          {t("editor.hide_field_modal.description")}
        </p>
        <div className="mt-4 flex flex-wrap justify-end gap-2">
          <button
            className="btn-outline btn-outline-sm"
            type="button"
            onClick={onCancel}
          >
            {t("editor.hide_field_modal.cancel")}
          </button>
          <button
            className="btn-outline btn-outline-sm"
            type="button"
            onClick={() => onResolve(false)}
          >
            {t("editor.hide_field_modal.keep_hidden_value")}
          </button>
          <button className="btn" type="button" onClick={() => onResolve(true)}>
            {t("editor.hide_field_modal.clear_and_hide")}
          </button>
        </div>
      </div>
    </div>
  );
}
