import React, { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

function normalizeCategory(value) {
  return String(value ?? "").trim();
}

function uniqueCategories(values) {
  const seen = new Set();
  const normalized = [];

  for (const value of Array.isArray(values) ? values : []) {
    const category = normalizeCategory(value);
    if (!category) {
      continue;
    }

    const key = category.toLowerCase();
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    normalized.push(category);
  }

  return normalized;
}

/**
 * Renders the category tag editor for contacts.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function CategoryTagEditor({
  categories,
  onChange,
  suggestions,
}) {
  const { t } = useTranslation("contacts");
  const safeCategories = useMemo(() => uniqueCategories(categories), [categories]);
  const [inputValue, setInputValue] = useState("");
  const [pickerOpen, setPickerOpen] = useState(false);

  const selectedKeys = useMemo(
    () => new Set(safeCategories.map((value) => value.toLowerCase())),
    [safeCategories],
  );

  const filteredSuggestions = useMemo(() => {
    const query = normalizeCategory(inputValue).toLowerCase();
    const seen = new Set();
    const available = [];

    for (const value of Array.isArray(suggestions) ? suggestions : []) {
      const category = normalizeCategory(value);
      if (!category) {
        continue;
      }

      const key = category.toLowerCase();
      if (selectedKeys.has(key) || seen.has(key)) {
        continue;
      }

      if (query && !key.includes(query)) {
        continue;
      }

      seen.add(key);
      available.push(category);
    }

    return available.slice(0, 8);
  }, [inputValue, selectedKeys, suggestions]);

  const addCategory = (rawValue) => {
    const category = normalizeCategory(rawValue);
    if (!category) {
      return;
    }

    const key = category.toLowerCase();
    if (selectedKeys.has(key)) {
      setInputValue("");
      setPickerOpen(false);
      return;
    }

    onChange([...safeCategories, category]);
    setInputValue("");
    setPickerOpen(false);
  };

  const removeCategory = (index) => {
    onChange(safeCategories.filter((_, rowIndex) => rowIndex !== index));
  };

  return (
    <section className="rounded-2xl border border-app-edge bg-app-surface p-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold uppercase tracking-wide text-app-base">
          {t("editor.categoryTagEditor.title", {
            defaultValue: "Categories",
          })}
        </h3>
      </div>
      <div className="mt-3 space-y-3">
        {safeCategories.length === 0 ? (
          <p className="text-sm text-app-faint">
            {t("editor.categoryTagEditor.empty", {
              defaultValue: "No categories yet.",
            })}
          </p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {safeCategories.map((category, index) => (
              <span
                key={`${category.toLowerCase()}-${index}`}
                className="inline-flex items-center gap-1 rounded-full border border-app-accent-edge bg-app-surface px-2.5 py-1 text-xs font-semibold text-app-base"
              >
                {category}
                <button
                  type="button"
                  className="inline-flex h-4 w-4 items-center justify-center rounded-full border border-app-edge text-[10px] leading-none text-app-faint hover:text-app-base"
                  onClick={() => removeCategory(index)}
                  aria-label={t("editor.categoryTagEditor.removeAria", {
                    defaultValue: "Remove {{category}}",
                    category,
                  })}
                >
                  ×
                </button>
              </span>
            ))}
          </div>
        )}

        <div className="relative">
          <input
            className="input"
            value={inputValue}
            onFocus={() => setPickerOpen(true)}
            onChange={(event) => {
              setInputValue(event.target.value);
              setPickerOpen(true);
            }}
            onBlur={() => {
              window.setTimeout(() => setPickerOpen(false), 80);
            }}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === ",") {
                event.preventDefault();
                addCategory(inputValue);
                return;
              }

              if (event.key === "Backspace" && normalizeCategory(inputValue) === "") {
                if (safeCategories.length > 0) {
                  removeCategory(safeCategories.length - 1);
                }
                return;
              }

              if (event.key === "Escape") {
                setPickerOpen(false);
              }
            }}
            placeholder={t("editor.categoryTagEditor.placeholder", {
              defaultValue: "Add category",
            })}
            role="combobox"
            aria-autocomplete="list"
            aria-expanded={pickerOpen}
            aria-controls="contact-categories-combobox-list"
          />

          {pickerOpen && filteredSuggestions.length > 0 ? (
            <div
              id="contact-categories-combobox-list"
              className="absolute z-30 mt-1 max-h-52 w-full overflow-auto rounded-xl border border-app-edge bg-app-surface p-1 shadow-lg backdrop-blur"
            >
              {filteredSuggestions.map((category) => (
                <button
                  key={category.toLowerCase()}
                  type="button"
                  className="mb-1 block w-full rounded-lg border border-transparent px-2.5 py-2 text-left text-sm text-app-base transition last:mb-0 hover:border-app-edge hover:bg-app-surface"
                  onMouseDown={(event) => {
                    event.preventDefault();
                    addCategory(category);
                  }}
                >
                  {category}
                </button>
              ))}
            </div>
          ) : null}
        </div>
      </div>
    </section>
  );
}
