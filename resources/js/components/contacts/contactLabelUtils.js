export const PHONE_LABEL_OPTIONS = [
  {
    value: "mobile",
    labelKey: "field_labels.phone.mobile",
    fallback: "Mobile",
  },
  {
    value: "iphone",
    labelKey: "field_labels.phone.iphone",
    fallback: "iPhone",
  },
  {
    value: "apple_watch",
    labelKey: "field_labels.phone.apple_watch",
    fallback: "Apple Watch",
  },
  { value: "home", labelKey: "field_labels.phone.home", fallback: "Home" },
  { value: "work", labelKey: "field_labels.phone.work", fallback: "Work" },
  { value: "main", labelKey: "field_labels.phone.main", fallback: "Main" },
  {
    value: "home_fax",
    labelKey: "field_labels.phone.home_fax",
    fallback: "Home Fax",
  },
  {
    value: "work_fax",
    labelKey: "field_labels.phone.work_fax",
    fallback: "Work Fax",
  },
  {
    value: "other_fax",
    labelKey: "field_labels.phone.other_fax",
    fallback: "Other Fax",
  },
  { value: "pager", labelKey: "field_labels.phone.pager", fallback: "Pager" },
  { value: "other", labelKey: "field_labels.phone.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.phone.custom",
    fallback: "Custom...",
  },
];

export const EMAIL_LABEL_OPTIONS = [
  { value: "home", labelKey: "field_labels.email.home", fallback: "Home" },
  { value: "work", labelKey: "field_labels.email.work", fallback: "Work" },
  { value: "other", labelKey: "field_labels.email.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.email.custom",
    fallback: "Custom...",
  },
];

export const URL_LABEL_OPTIONS = [
  {
    value: "homepage",
    labelKey: "field_labels.url.homepage",
    fallback: "Home Page",
  },
  { value: "home", labelKey: "field_labels.url.home", fallback: "Home" },
  { value: "work", labelKey: "field_labels.url.work", fallback: "Work" },
  { value: "other", labelKey: "field_labels.url.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.url.custom",
    fallback: "Custom...",
  },
];

export const ADDRESS_LABEL_OPTIONS = [
  { value: "home", labelKey: "field_labels.address.home", fallback: "Home" },
  { value: "work", labelKey: "field_labels.address.work", fallback: "Work" },
  {
    value: "school",
    labelKey: "field_labels.address.school",
    fallback: "School",
  },
  { value: "other", labelKey: "field_labels.address.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.address.custom",
    fallback: "Custom...",
  },
];

export const DATE_LABEL_OPTIONS = [
  {
    value: "anniversary",
    labelKey: "field_labels.date.anniversary",
    fallback: "Anniversary",
  },
  { value: "other", labelKey: "field_labels.date.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.date.custom",
    fallback: "Custom...",
  },
];

export const RELATED_LABEL_OPTIONS = [
  {
    value: "spouse",
    labelKey: "field_labels.related.spouse",
    fallback: "Spouse",
  },
  {
    value: "partner",
    labelKey: "field_labels.related.partner",
    fallback: "Partner",
  },
  {
    value: "parent",
    labelKey: "field_labels.related.parent",
    fallback: "Parent",
  },
  { value: "child", labelKey: "field_labels.related.child", fallback: "Child" },
  {
    value: "sibling",
    labelKey: "field_labels.related.sibling",
    fallback: "Sibling",
  },
  {
    value: "assistant",
    labelKey: "field_labels.related.assistant",
    fallback: "Assistant",
  },
  {
    value: "friend",
    labelKey: "field_labels.related.friend",
    fallback: "Friend",
  },
  { value: "other", labelKey: "field_labels.related.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.related.custom",
    fallback: "Custom...",
  },
];

/**
 * Canonical relationship tokens used for matching/sync logic across frontend
 * and backend/vCard services. These are not user-facing strings and should
 * remain stable English-like identifiers (not localized).
 */
const RELATED_LABEL_DERIVED_VALUES = new Set([
  "spouse",
  "husband",
  "wife",
  "partner",
  "boyfriend",
  "girlfriend",
  "fiance",
  "fiancee",
  "parent",
  "father",
  "mother",
  "dad",
  "mom",
  "child",
  "son",
  "daughter",
  "stepson",
  "stepdaughter",
  "parent_in_law",
  "father_in_law",
  "mother_in_law",
  "child_in_law",
  "son_in_law",
  "daughter_in_law",
  "sibling",
  "brother",
  "sister",
  "sibling_in_law",
  "brother_in_law",
  "sister_in_law",
  "aunt_uncle",
  "aunt",
  "uncle",
  "niece_nephew",
  "niece",
  "nephew",
  "grandparent",
  "grandfather",
  "grandpa",
  "grandmother",
  "grandma",
  "grandchild",
  "grandson",
  "granddaughter",
  "cousin",
  "assistant",
  "friend",
  "other",
]);

const RELATED_LABEL_DISPLAY_OVERRIDES = {
  parent_in_law: {
    labelKey: "field_labels.related_derived.parent_in_law",
    fallback: "Parent-in-Law",
  },
  father_in_law: {
    labelKey: "field_labels.related_derived.father_in_law",
    fallback: "Father-in-Law",
  },
  mother_in_law: {
    labelKey: "field_labels.related_derived.mother_in_law",
    fallback: "Mother-in-Law",
  },
  child_in_law: {
    labelKey: "field_labels.related_derived.child_in_law",
    fallback: "Child-in-Law",
  },
  son_in_law: {
    labelKey: "field_labels.related_derived.son_in_law",
    fallback: "Son-in-Law",
  },
  daughter_in_law: {
    labelKey: "field_labels.related_derived.daughter_in_law",
    fallback: "Daughter-in-Law",
  },
  sibling_in_law: {
    labelKey: "field_labels.related_derived.sibling_in_law",
    fallback: "Sibling-in-Law",
  },
  brother_in_law: {
    labelKey: "field_labels.related_derived.brother_in_law",
    fallback: "Brother-in-Law",
  },
  sister_in_law: {
    labelKey: "field_labels.related_derived.sister_in_law",
    fallback: "Sister-in-Law",
  },
  aunt_uncle: {
    labelKey: "field_labels.related_derived.aunt_uncle",
    fallback: "Aunt/Uncle",
  },
  niece_nephew: {
    labelKey: "field_labels.related_derived.niece_nephew",
    fallback: "Niece/Nephew",
  },
  grandpa: {
    labelKey: "field_labels.related_derived.grandpa",
    fallback: "Grandpa",
  },
  grandma: {
    labelKey: "field_labels.related_derived.grandma",
    fallback: "Grandma",
  },
};

export const IM_LABEL_OPTIONS = [
  { value: "home", labelKey: "field_labels.im.home", fallback: "Home" },
  { value: "work", labelKey: "field_labels.im.work", fallback: "Work" },
  { value: "other", labelKey: "field_labels.im.other", fallback: "Other" },
  {
    value: "custom",
    labelKey: "field_labels.im.custom",
    fallback: "Custom...",
  },
];

const SAVED_CUSTOM_LABEL_VALUE_PREFIX = "saved-custom:";
const CONTACT_LABEL_FIELD_OPTIONS = {
  phones: PHONE_LABEL_OPTIONS,
  emails: EMAIL_LABEL_OPTIONS,
  urls: URL_LABEL_OPTIONS,
  addresses: ADDRESS_LABEL_OPTIONS,
  dates: DATE_LABEL_OPTIONS,
  related_names: RELATED_LABEL_OPTIONS,
  instant_messages: IM_LABEL_OPTIONS,
};
const CONTACT_LABEL_FIELD_KEYS = Object.keys(CONTACT_LABEL_FIELD_OPTIONS);
const CONTACT_LABEL_BUILTIN_VALUE_SETS = Object.fromEntries(
  CONTACT_LABEL_FIELD_KEYS.map((fieldKey) => [
    fieldKey,
    new Set(
      CONTACT_LABEL_FIELD_OPTIONS[fieldKey]
        .map((option) =>
          String(option?.value ?? "")
            .trim()
            .toLowerCase(),
        )
        .filter((value) => value !== "" && value !== "custom"),
    ),
  ]),
);
const EMPTY_CONTACT_CUSTOM_LABELS = Object.fromEntries(
  CONTACT_LABEL_FIELD_KEYS.map((fieldKey) => [fieldKey, []]),
);

function normalizeLabelValue(value) {
  return String(value ?? "")
    .trim()
    .toLowerCase();
}

function normalizeCustomLabelText(value) {
  return String(value ?? "")
    .replace(/\s+/g, " ")
    .trim();
}

function customLabelKey(value) {
  return normalizeCustomLabelText(value).toLowerCase();
}

function savedCustomOptionValue(label) {
  return `${SAVED_CUSTOM_LABEL_VALUE_PREFIX}${customLabelKey(label)}`;
}

/**
 * Collects custom labels used across contacts, grouped by contact field key.
 *
 * @param {unknown} contacts
 * @returns {Record<string, string[]>}
 */
export function buildSavedCustomLabelsByField(contacts) {
  const mapsByField = Object.fromEntries(
    CONTACT_LABEL_FIELD_KEYS.map((fieldKey) => [fieldKey, new Map()]),
  );

  if (!Array.isArray(contacts) || contacts.length === 0) {
    return EMPTY_CONTACT_CUSTOM_LABELS;
  }

  for (const contact of contacts) {
    for (const fieldKey of CONTACT_LABEL_FIELD_KEYS) {
      const builtInValues = CONTACT_LABEL_BUILTIN_VALUE_SETS[fieldKey];
      const rows = Array.isArray(contact?.[fieldKey]) ? contact[fieldKey] : [];

      for (const row of rows) {
        if (!row || typeof row !== "object") {
          continue;
        }

        const normalizedLabel = normalizeLabelValue(row?.label);
        if (normalizedLabel !== "custom") {
          continue;
        }

        const candidateLabel = normalizeCustomLabelText(row?.custom_label);

        const candidateKey = customLabelKey(candidateLabel);
        if (candidateKey === "" || builtInValues.has(candidateKey)) {
          continue;
        }

        if (!mapsByField[fieldKey].has(candidateKey)) {
          mapsByField[fieldKey].set(candidateKey, candidateLabel);
        }
      }
    }
  }

  return Object.fromEntries(
    CONTACT_LABEL_FIELD_KEYS.map((fieldKey) => [
      fieldKey,
      Array.from(mapsByField[fieldKey].values()).sort((left, right) =>
        left.localeCompare(right, undefined, {
          sensitivity: "base",
        }),
      ),
    ]),
  );
}

/**
 * Builds label select options with saved custom labels inserted before "Custom...".
 *
 * @param {Array<{value: string, label: string}>} baseOptions
 * @param {string[]} [savedCustomLabels=[]]
 * @returns {Array<{value: string, label: string, saved_custom_label?: string, saved_custom_key?: string}>}
 */
export function buildLabelOptions(baseOptions, savedCustomLabels = []) {
  const primaryOptions = baseOptions.filter(
    (option) => option.value !== "custom",
  );
  const customOption = baseOptions.find((option) => option.value === "custom");
  const customLabelOptions = savedCustomLabels.map((label) => ({
    value: savedCustomOptionValue(label),
    label,
    saved_custom_label: label,
    saved_custom_key: customLabelKey(label),
  }));

  if (!customOption) {
    return [...primaryOptions, ...customLabelOptions];
  }

  return [...primaryOptions, ...customLabelOptions, customOption];
}

function formatRelatedLabelOptionLabel(value) {
  const normalized = normalizeLabelValue(value);
  if (!normalized) {
    return { label: "" };
  }

  if (RELATED_LABEL_DISPLAY_OVERRIDES[normalized]) {
    const displayOverride = RELATED_LABEL_DISPLAY_OVERRIDES[normalized];
    return {
      ...displayOverride,
      label: displayOverride.fallback,
    };
  }

  return {
    label: normalized
      .split(/[\s_-]+/)
      .filter(Boolean)
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(" "),
  };
}

/**
 * Derives additional related-name label options from existing contact data.
 *
 * @param {unknown} contacts
 * @returns {Array<{value: string, label?: string, labelKey?: string, fallback?: string}>}
 */
export function buildDerivedRelatedLabelOptions(contacts) {
  if (!Array.isArray(contacts) || contacts.length === 0) {
    return [];
  }

  const builtInValues =
    CONTACT_LABEL_BUILTIN_VALUE_SETS.related_names ?? new Set();
  const derivedValues = new Set();

  for (const contact of contacts) {
    const rows = Array.isArray(contact?.related_names)
      ? contact.related_names
      : [];

    for (const row of rows) {
      const normalizedLabel = normalizeLabelValue(row?.label);
      if (
        normalizedLabel === "" ||
        normalizedLabel === "custom" ||
        builtInValues.has(normalizedLabel) ||
        !RELATED_LABEL_DERIVED_VALUES.has(normalizedLabel)
      ) {
        continue;
      }

      derivedValues.add(normalizedLabel);
    }
  }

  return Array.from(derivedValues)
    .sort((left, right) =>
      left.localeCompare(right, undefined, {
        sensitivity: "base",
      }),
    )
    .map((value) => ({
      value,
      ...formatRelatedLabelOptionLabel(value),
    }));
}

/**
 * Builds related-name label options with built-in, saved custom, and derived labels.
 *
 * @param {unknown} contacts
 * @param {string[]} [savedCustomLabels=[]]
 * @returns {Array<{value: string, label: string, saved_custom_label?: string, saved_custom_key?: string}>}
 */
export function buildRelatedNameLabelOptions(contacts, savedCustomLabels = []) {
  const baseOptions = buildLabelOptions(
    RELATED_LABEL_OPTIONS,
    savedCustomLabels,
  );
  const derivedOptions = buildDerivedRelatedLabelOptions(contacts);
  if (derivedOptions.length === 0) {
    return baseOptions;
  }

  const customOption = baseOptions.find(
    (option) => normalizeLabelValue(option?.value) === "custom",
  );
  const nonCustomOptions = baseOptions.filter(
    (option) => normalizeLabelValue(option?.value) !== "custom",
  );
  const existingValues = new Set(
    nonCustomOptions.map((option) => normalizeLabelValue(option?.value)),
  );
  const dedupedDerivedOptions = derivedOptions.filter(
    (option) => !existingValues.has(normalizeLabelValue(option?.value)),
  );
  const dedupedDerivedKeys = new Set(
    dedupedDerivedOptions.map((option) => normalizeLabelValue(option?.value)),
  );
  const dedupedOptions = nonCustomOptions.filter(
    (option) =>
      !option?.saved_custom_key ||
      !dedupedDerivedKeys.has(normalizeLabelValue(option.saved_custom_key)),
  );

  if (!customOption) {
    return [...dedupedOptions, ...dedupedDerivedOptions];
  }

  return [...dedupedOptions, ...dedupedDerivedOptions, customOption];
}

/**
 * Resolves the selected option value for a row's current label/custom-label state.
 *
 * @param {Record<string, any>} row
 * @param {Array<{value: string, saved_custom_key?: string}>} labelOptions
 * @param {string} [fallbackValue='other']
 * @returns {string}
 */
export function resolveLabelSelectValue(
  row,
  labelOptions,
  fallbackValue = "other",
) {
  const normalizedLabel = normalizeLabelValue(row?.label);

  if (normalizedLabel === "custom") {
    const selectedCustomKey = customLabelKey(row?.custom_label);
    if (selectedCustomKey !== "") {
      const customOption = labelOptions.find(
        (option) => option.saved_custom_key === selectedCustomKey,
      );
      if (customOption) {
        return customOption.value;
      }
    }

    return "custom";
  }

  const directOption = labelOptions.find(
    (option) => normalizeLabelValue(option.value) === normalizedLabel,
  );
  if (directOption) {
    return directOption.value;
  }

  return fallbackValue;
}
