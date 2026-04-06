import React from "react";
import { useTranslation } from "react-i18next";
import { TrashIcon } from "../icons/AppIcons";

/**
 * Renders the Date Editor component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DateEditor({
  rows,
  setRows,
  labelOptions,
  defaultLabelOptions,
  resolveLabelSelectValue,
  createEmptyDate,
  normalizeDatePartInput,
}) {
  const { t } = useTranslation("contacts");
  const safeRows = Array.isArray(rows) ? rows : [];
  const safeLabelOptions =
    Array.isArray(labelOptions) && labelOptions.length > 0
      ? labelOptions
      : defaultLabelOptions;
  const optionLabel = (option) => {
    if (typeof option?.labelKey === "string" && option.labelKey.trim() !== "") {
      return t(option.labelKey, {
        defaultValue: option?.fallback ?? option?.value ?? "",
      });
    }

    if (typeof option?.label === "string" && option.label.trim() !== "") {
      return option.label;
    }

    return option?.fallback ?? option?.value ?? "";
  };

  const updateRow = (index, field, value) => {
    if (field === "label" || field === "custom_label") {
      setRows(
        safeRows.map((row, rowIndex) =>
          rowIndex === index ? { ...row, [field]: value } : row,
        ),
      );
      return;
    }

    const normalizedValue = normalizeDatePartInput(field, value);
    setRows(
      safeRows.map((row, rowIndex) =>
        rowIndex === index ? { ...row, [field]: normalizedValue } : row,
      ),
    );
  };

  const updateLabel = (index, nextValue) => {
    const selectedOption = safeLabelOptions.find(
      (option) => option.value === nextValue,
    );

    if (selectedOption?.saved_custom_label) {
      setRows(
        safeRows.map((row, rowIndex) =>
          rowIndex === index
            ? {
                ...row,
                label: "custom",
                custom_label: selectedOption.saved_custom_label,
              }
            : row,
        ),
      );
      return;
    }

    if (nextValue === "custom") {
      setRows(
        safeRows.map((row, rowIndex) =>
          rowIndex === index
            ? {
                ...row,
                label: "custom",
                custom_label: row?.custom_label ?? "",
              }
            : row,
        ),
      );
      return;
    }

    setRows(
      safeRows.map((row, rowIndex) =>
        rowIndex === index
          ? {
              ...row,
              label: nextValue,
              custom_label: "",
            }
          : row,
      ),
    );
  };

  const addRow = () => {
    setRows([...safeRows, createEmptyDate("other")]);
  };

  const removeRow = (index) => {
    setRows(safeRows.filter((_, rowIndex) => rowIndex !== index));
  };

  return (
    <section className="rounded-2xl border border-app-edge bg-app-surface p-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold uppercase tracking-wide text-app-base">
          {t("editor.dateEditor.title")}
        </h3>
        <button
          className="btn-outline btn-outline-sm"
          type="button"
          onClick={addRow}
        >
          {t("editor.dateEditor.addDate")}
        </button>
      </div>
      <div className="mt-3 space-y-3">
        {safeRows.length === 0 ? (
          <p className="text-sm text-app-faint">
            {t("editor.dateEditor.noDates")}
          </p>
        ) : (
          safeRows.map((row, index) => (
            <div
              key={`date-${index}`}
              className="rounded-xl border border-app-edge p-3"
            >
              <div className="grid gap-3 md:grid-cols-[12rem_1fr_auto]">
                <select
                  className="input"
                  value={resolveLabelSelectValue(
                    row,
                    safeLabelOptions,
                    "other",
                  )}
                  onChange={(event) => updateLabel(index, event.target.value)}
                >
                  {safeLabelOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {optionLabel(option)}
                    </option>
                  ))}
                </select>
                <div className="grid gap-2 sm:grid-cols-3">
                  <input
                    className="input"
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    maxLength={2}
                    value={row.month ?? ""}
                    placeholder={t("editor.dateEditor.monthPlaceholder")}
                    onChange={(event) =>
                      updateRow(index, "month", event.target.value)
                    }
                  />
                  <input
                    className="input"
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    maxLength={2}
                    value={row.day ?? ""}
                    placeholder={t("editor.dateEditor.dayPlaceholder")}
                    onChange={(event) =>
                      updateRow(index, "day", event.target.value)
                    }
                  />
                  <input
                    className="input"
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    maxLength={4}
                    value={row.year ?? ""}
                    placeholder={t("editor.dateEditor.yearPlaceholder")}
                    onChange={(event) =>
                      updateRow(index, "year", event.target.value)
                    }
                  />
                </div>
                <button
                  className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent bg-transparent p-0 text-app-dim transition hover:text-app-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 sm:h-auto sm:w-auto sm:rounded-xl sm:border sm:border-app-edge sm:bg-app-surface sm:px-2.5 sm:py-1 sm:text-xs sm:font-semibold sm:text-app-base"
                  type="button"
                  onClick={() => removeRow(index)}
                  aria-label={t("editor.dateEditor.remove")}
                >
                  <TrashIcon className="h-3.5 w-3.5 sm:hidden" />
                  <span className="hidden sm:inline">
                    {t("editor.dateEditor.remove")}
                  </span>
                </button>
              </div>
              {row.label === "custom" ? (
                <input
                  className="input mt-2"
                  value={row.custom_label ?? ""}
                  onChange={(event) =>
                    updateRow(index, "custom_label", event.target.value)
                  }
                  placeholder={t("editor.dateEditor.customLabelPlaceholder")}
                />
              ) : null}
            </div>
          ))
        )}
      </div>
    </section>
  );
}
