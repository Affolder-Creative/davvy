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
          {t("editor.hideFieldModal.title", { pendingHideFieldLabel })}
        </h3>
        <p className="mt-2 text-sm text-app-muted">
          {t("editor.hideFieldModal.description")}
        </p>
        <div className="mt-4 flex flex-wrap justify-end gap-2">
          <button
            className="btn-outline btn-outline-sm"
            type="button"
            onClick={onCancel}
          >
            {t("editor.hideFieldModal.cancel")}
          </button>
          <button
            className="btn-outline btn-outline-sm"
            type="button"
            onClick={() => onResolve(false)}
          >
            {t("editor.hideFieldModal.keepHiddenValue")}
          </button>
          <button className="btn" type="button" onClick={() => onResolve(true)}>
            {t("editor.hideFieldModal.clearAndHide")}
          </button>
        </div>
      </div>
    </div>
  );
}
