import { useEffect, useMemo, useState } from "react";

/**
 * Central state manager for contact list filtering, editor form state, and CRUD actions.
 *
 * @param {Record<string, any>} deps
 * @returns {Record<string, any>}
 */
export default function useContactsPageState({
  auth,
  api,
  extractError,
  createEmptyContactForm,
  OPTIONAL_CONTACT_FIELDS,
  getOptionalFieldLabel,
  createContactSectionOpenState,
  normalizePositiveInt,
  buildSavedCustomLabelsByField,
  buildLabelOptions,
  PHONE_LABEL_OPTIONS,
  EMAIL_LABEL_OPTIONS,
  URL_LABEL_OPTIONS,
  ADDRESS_LABEL_OPTIONS,
  DATE_LABEL_OPTIONS,
  buildRelatedNameLabelOptions,
  IM_LABEL_OPTIONS,
  CONTACTS_PAGE_SIZE,
  hasTextValue,
  deriveOptionalFieldVisibility,
  deriveContactSectionOpenState,
  hydrateContactForm,
  normalizeDatePartInput,
  normalizeDatePartsForPayload,
  normalizeDateRowsForPayload,
  optionalFieldHasValue,
  clearOptionalFieldValue,
  navigate,
}) {
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [queueStatusNotice, setQueueStatusNotice] = useState("");
  const [contacts, setContacts] = useState([]);
  const [addressBooks, setAddressBooks] = useState([]);
  const [selectedContactId, setSelectedContactId] = useState(null);
  const [form, setForm] = useState(createEmptyContactForm());
  const [visibleOptionalFields, setVisibleOptionalFields] = useState([]);
  const [fieldToAdd, setFieldToAdd] = useState(
    OPTIONAL_CONTACT_FIELDS[0]?.id ?? "",
  );
  const [fieldSearchTerm, setFieldSearchTerm] = useState("");
  const [fieldPickerOpen, setFieldPickerOpen] = useState(false);
  const [pendingHideFieldId, setPendingHideFieldId] = useState(null);
  const [contactSearchTerm, setContactSearchTerm] = useState("");
  const [contactAddressBookFilter, setContactAddressBookFilter] =
    useState("all");
  const [contactsPage, setContactsPage] = useState(1);
  const [openSections, setOpenSections] = useState(
    createContactSectionOpenState(),
  );
  const [photoConstraints, setPhotoConstraints] = useState({
    max_upload_kb: 8192,
    min_crop_size: 600,
    output_size: 1024,
    allowed_mimes: ["image/jpeg", "image/png", "image/webp"],
  });

  const optionalFieldsWithLabels = useMemo(
    () =>
      OPTIONAL_CONTACT_FIELDS.map((field) => {
        const fallbackLabel = String(
          field?.label ?? field?.fallback ?? field?.id ?? "",
        );
        if (typeof getOptionalFieldLabel !== "function") {
          return { ...field, resolvedLabel: fallbackLabel };
        }

        const translatedLabel = String(getOptionalFieldLabel(field) ?? "").trim();
        return {
          ...field,
          resolvedLabel: translatedLabel !== "" ? translatedLabel : fallbackLabel,
        };
      }),
    [OPTIONAL_CONTACT_FIELDS, getOptionalFieldLabel],
  );

  const defaultAddressBookIds = useMemo(
    () => (addressBooks[0] ? [addressBooks[0].id] : []),
    [addressBooks],
  );

  const hiddenOptionalFields = useMemo(
    () =>
      optionalFieldsWithLabels.filter(
        (field) => !visibleOptionalFields.includes(field.id),
      ),
    [visibleOptionalFields, optionalFieldsWithLabels],
  );

  const categoryOptions = useMemo(() => {
    const seen = new Set();
    const collected = [];

    for (const contact of Array.isArray(contacts) ? contacts : []) {
      const categories = Array.isArray(contact?.categories)
        ? contact.categories
        : [];

      for (const value of categories) {
        const category = String(value ?? "").trim();
        if (!category) {
          continue;
        }

        const key = category.toLowerCase();
        if (seen.has(key)) {
          continue;
        }

        seen.add(key);
        collected.push(category);
      }
    }

    return collected.sort((left, right) =>
      left.localeCompare(right, undefined, {
        sensitivity: "base",
      }),
    );
  }, [contacts]);

  const relatedNameOptions = useMemo(() => {
    const activeContactId = normalizePositiveInt(form.id);

    return contacts
      .map((contact) => ({
        id: normalizePositiveInt(contact?.id),
        display_name: String(contact?.display_name ?? "").trim(),
        nickname: String(contact?.nickname ?? "").trim(),
      }))
      .filter(
        (contact) =>
          contact.id !== null &&
          contact.display_name !== "" &&
          contact.id !== activeContactId,
      )
      .sort((left, right) =>
        left.display_name.localeCompare(right.display_name, undefined, {
          sensitivity: "base",
        }),
      );
  }, [contacts, form.id, normalizePositiveInt]);

  const savedCustomLabels = useMemo(
    () => buildSavedCustomLabelsByField(contacts),
    [contacts, buildSavedCustomLabelsByField],
  );

  const labelOptions = useMemo(
    () => ({
      phones: buildLabelOptions(PHONE_LABEL_OPTIONS, savedCustomLabels.phones),
      emails: buildLabelOptions(EMAIL_LABEL_OPTIONS, savedCustomLabels.emails),
      urls: buildLabelOptions(URL_LABEL_OPTIONS, savedCustomLabels.urls),
      addresses: buildLabelOptions(
        ADDRESS_LABEL_OPTIONS,
        savedCustomLabels.addresses,
      ),
      dates: buildLabelOptions(DATE_LABEL_OPTIONS, savedCustomLabels.dates),
      related_names: buildRelatedNameLabelOptions(
        contacts,
        savedCustomLabels.related_names,
      ),
      instant_messages: buildLabelOptions(
        IM_LABEL_OPTIONS,
        savedCustomLabels.instant_messages,
      ),
    }),
    [
      contacts,
      savedCustomLabels,
      buildLabelOptions,
      PHONE_LABEL_OPTIONS,
      EMAIL_LABEL_OPTIONS,
      URL_LABEL_OPTIONS,
      ADDRESS_LABEL_OPTIONS,
      DATE_LABEL_OPTIONS,
      buildRelatedNameLabelOptions,
      IM_LABEL_OPTIONS,
    ],
  );

  const filteredHiddenOptionalFields = useMemo(() => {
    const query = fieldSearchTerm.trim().toLowerCase();
    if (!query) {
      return hiddenOptionalFields;
    }

    return hiddenOptionalFields.filter((field) =>
      String(field?.resolvedLabel ?? "")
        .toLowerCase()
        .includes(query),
    );
  }, [fieldSearchTerm, hiddenOptionalFields]);

  const filteredContacts = useMemo(() => {
    const query = contactSearchTerm.trim().toLowerCase();
    const activeAddressBookId =
      contactAddressBookFilter === "all"
        ? null
        : Number(contactAddressBookFilter);

    const searchValueIncludesQuery = (value) =>
      String(value ?? "")
        .toLowerCase()
        .includes(query);

    return contacts.filter((contact) => {
      if (activeAddressBookId !== null) {
        const assignedBookIds = Array.isArray(contact.address_book_ids)
          ? contact.address_book_ids
          : [];

        if (!assignedBookIds.some((id) => Number(id) === activeAddressBookId)) {
          return false;
        }
      }

      if (!query) {
        return true;
      }

      if (
        [
          contact.first_name,
          contact.middle_name,
          contact.last_name,
          contact.nickname,
          contact.maiden_name,
        ].some(searchValueIncludesQuery)
      ) {
        return true;
      }

      if (
        Array.isArray(contact.categories) &&
        contact.categories.some(searchValueIncludesQuery)
      ) {
        return true;
      }

      return false;
    });
  }, [contactAddressBookFilter, contactSearchTerm, contacts]);

  const totalContactPages = Math.max(
    1,
    Math.ceil(filteredContacts.length / CONTACTS_PAGE_SIZE),
  );
  const currentContactPage = Math.min(contactsPage, totalContactPages);
  const firstContactIndex = (currentContactPage - 1) * CONTACTS_PAGE_SIZE;
  const paginatedContacts = filteredContacts.slice(
    firstContactIndex,
    firstContactIndex + CONTACTS_PAGE_SIZE,
  );
  const lastContactIndex =
    filteredContacts.length === 0
      ? 0
      : firstContactIndex + paginatedContacts.length;
  const hasContactFilters =
    hasTextValue(contactSearchTerm) || contactAddressBookFilter !== "all";

  useEffect(() => {
    setContactsPage(1);
  }, [contactAddressBookFilter, contactSearchTerm]);

  useEffect(() => {
    if (contactAddressBookFilter === "all") {
      return;
    }

    const filterExists = addressBooks.some(
      (book) => String(book.id) === contactAddressBookFilter,
    );

    if (!filterExists) {
      setContactAddressBookFilter("all");
    }
  }, [addressBooks, contactAddressBookFilter]);

  useEffect(() => {
    setContactsPage((prevPage) =>
      prevPage > totalContactPages ? totalContactPages : prevPage,
    );
  }, [totalContactPages]);

  useEffect(() => {
    if (hiddenOptionalFields.length === 0) {
      setFieldToAdd("");
      setFieldSearchTerm("");
      setFieldPickerOpen(false);
      return;
    }

    if (filteredHiddenOptionalFields.length === 0) {
      setFieldToAdd("");
      return;
    }

    if (!filteredHiddenOptionalFields.some((field) => field.id === fieldToAdd)) {
      setFieldToAdd(filteredHiddenOptionalFields[0].id);
    }
  }, [fieldToAdd, filteredHiddenOptionalFields, hiddenOptionalFields]);

  const applyFormState = (nextForm) => {
    setForm(nextForm);
    setVisibleOptionalFields(deriveOptionalFieldVisibility(nextForm));
    setOpenSections(deriveContactSectionOpenState(nextForm));
  };

  const redirectIfFeatureDisabled = async (err) => {
    const status = err?.response?.status;
    const message = String(err?.response?.data?.message ?? "").toLowerCase();

    if (status !== 403 || !message.includes("contact management")) {
      return false;
    }

    await auth.refreshAuth();
    navigate("/", { replace: true });
    return true;
  };

  const loadContacts = async ({
    preserveSelection = true,
    selectId = undefined,
  } = {}) => {
    setError("");
    setLoading(true);

    try {
      const response = await api.get("/api/contacts");
      const nextContacts = Array.isArray(response.data?.contacts)
        ? response.data.contacts
        : [];
      const nextAddressBooks = Array.isArray(response.data?.address_books)
        ? response.data.address_books
        : [];
      const nextPhotoConstraints =
        response.data?.photo_constraints &&
        typeof response.data.photo_constraints === "object"
          ? response.data.photo_constraints
          : {};

      setContacts(nextContacts);
      setAddressBooks(nextAddressBooks);
      setPhotoConstraints({
        max_upload_kb: Number(nextPhotoConstraints.max_upload_kb ?? 8192) || 8192,
        min_crop_size: Number(nextPhotoConstraints.min_crop_size ?? 600) || 600,
        output_size: Number(nextPhotoConstraints.output_size ?? 1024) || 1024,
        allowed_mimes: Array.isArray(nextPhotoConstraints.allowed_mimes)
          ? nextPhotoConstraints.allowed_mimes
              .map((value) => String(value ?? "").trim().toLowerCase())
              .filter((value) => value !== "")
          : ["image/jpeg", "image/png", "image/webp"],
      });

      const fallbackIds = nextAddressBooks[0] ? [nextAddressBooks[0].id] : [];
      const hasExplicitSelectId = selectId !== undefined;
      const explicitContactId =
        hasExplicitSelectId &&
        selectId !== null &&
        nextContacts.some((contact) => contact.id === selectId)
          ? selectId
          : null;
      const preservedContactId =
        preserveSelection &&
        selectedContactId &&
        nextContacts.some((contact) => contact.id === selectedContactId)
          ? selectedContactId
          : null;
      const activeId = hasExplicitSelectId
        ? explicitContactId
        : preservedContactId;

      setSelectedContactId(activeId);

      const activeContact = nextContacts.find((contact) => contact.id === activeId);
      applyFormState(hydrateContactForm(activeContact, fallbackIds));
    } catch (err) {
      if (await redirectIfFeatureDisabled(err)) {
        return;
      }
      setError(extractError(err, "Unable to load contacts."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadContacts({ preserveSelection: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!queueStatusNotice) {
      return undefined;
    }

    const timer = window.setTimeout(() => setQueueStatusNotice(""), 2600);
    return () => window.clearTimeout(timer);
  }, [queueStatusNotice]);

  const startNewContact = () => {
    setSelectedContactId(null);
    setError("");
    applyFormState(createEmptyContactForm(defaultAddressBookIds));
  };

  const selectContact = (contact) => {
    setSelectedContactId(contact.id);
    setError("");
    applyFormState(hydrateContactForm(contact, defaultAddressBookIds));
  };

  const updateFormField = (field, value) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const updateBirthdayField = (field, value) => {
    const normalizedValue = normalizeDatePartInput(field, value);
    setForm((prev) => ({
      ...prev,
      birthday: {
        ...prev.birthday,
        [field]: normalizedValue,
      },
    }));
  };

  const saveContact = async (event) => {
    event.preventDefault();

    if (!hasRequiredContactIdentity) {
      setError("Enter at least a First Name, Last Name, or Company.");
      return;
    }

    if (
      !Array.isArray(form.address_book_ids) ||
      form.address_book_ids.length === 0
    ) {
      setError("Select at least one address book.");
      return;
    }

    setSubmitting(true);
    setError("");

    const payload = {
      ...form,
      birthday: normalizeDatePartsForPayload(form.birthday),
      dates: normalizeDateRowsForPayload(form.dates),
      address_book_ids: form.address_book_ids.map((id) => Number(id)),
      photo_upload_token: form.photo_upload_token || null,
      photo_remove: !!form.photo_remove,
    };
    delete payload.id;
    delete payload.photo;

    try {
      const response = form.id
        ? await api.patch(`/api/contacts/${form.id}`, payload)
        : await api.post("/api/contacts", payload);

      if (response?.data?.queued) {
        setQueueStatusNotice(
          response.data?.message || "Change submitted for owner/admin approval.",
        );
        await loadContacts({
          preserveSelection: false,
          selectId: null,
        });
        return;
      }

      await loadContacts({
        preserveSelection: false,
        selectId: null,
      });
    } catch (err) {
      if (await redirectIfFeatureDisabled(err)) {
        return;
      }
      setError(extractError(err, "Unable to save contact."));
    } finally {
      setSubmitting(false);
    }
  };

  const removeContact = async () => {
    if (!form.id) {
      return;
    }

    if (!window.confirm("Delete this contact from all assigned address books?")) {
      return;
    }

    setSubmitting(true);
    setError("");

    try {
      const response = await api.delete(`/api/contacts/${form.id}`);

      if (response?.data?.queued) {
        setQueueStatusNotice(
          response.data?.message ||
            "Delete request submitted for owner/admin approval.",
        );
        await loadContacts({ preserveSelection: true });
        return;
      }

      await loadContacts({ preserveSelection: false, selectId: null });
    } catch (err) {
      if (await redirectIfFeatureDisabled(err)) {
        return;
      }
      setError(extractError(err, "Unable to delete contact."));
    } finally {
      setSubmitting(false);
    }
  };

  const stageContactPhotoUpload = async ({ file, crop }) => {
    const endpoint = form.id
      ? `/api/contacts/${form.id}/photo/stage`
      : "/api/contacts/photos/stage";
    const data = new FormData();
    data.append("photo", file);
    data.append("crop_x", String(Math.max(0, Math.round(crop.x ?? 0))));
    data.append("crop_y", String(Math.max(0, Math.round(crop.y ?? 0))));
    data.append(
      "crop_width",
      String(Math.max(1, Math.round(crop.width ?? 1))),
    );
    data.append(
      "crop_height",
      String(Math.max(1, Math.round(crop.height ?? 1))),
    );

    const response = await api.post(endpoint, data, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    const token = String(response.data?.token ?? "").trim();
    if (!token) {
      throw new Error("Photo upload token missing from server response.");
    }

    setForm((prev) => ({
      ...prev,
      photo_upload_token: token,
      photo_remove: false,
    }));

    return response.data ?? {};
  };

  const removePhotoFromForm = () => {
    setForm((prev) => {
      const hasPersistedPhoto = !!(
        prev.photo && typeof prev.photo.url === "string" && prev.photo.url !== ""
      );

      return {
        ...prev,
        photo_upload_token: null,
        photo_remove: hasPersistedPhoto,
      };
    });
  };

  const undoPhotoRemoval = () => {
    setForm((prev) => ({
      ...prev,
      photo_upload_token: null,
      photo_remove: false,
    }));
  };

  const clearPendingPhotoUpload = () => {
    setForm((prev) => ({
      ...prev,
      photo_upload_token: null,
    }));
  };

  const toggleAssignedAddressBook = (addressBookId, checked) => {
    setForm((prev) => {
      const current = Array.isArray(prev.address_book_ids)
        ? [...prev.address_book_ids]
        : [];

      if (checked) {
        if (!current.includes(addressBookId)) {
          current.push(addressBookId);
        }
      } else {
        const next = current.filter((id) => id !== addressBookId);
        return { ...prev, address_book_ids: next };
      }

      return { ...prev, address_book_ids: current };
    });
  };

  const showOptionalField = (fieldId) => {
    if (!fieldId) {
      return;
    }

    setVisibleOptionalFields((prev) =>
      prev.includes(fieldId) ? prev : [...prev, fieldId],
    );
  };

  const hideOptionalField = (fieldId) => {
    if (!fieldId) {
      return;
    }

    if (optionalFieldHasValue(form, fieldId)) {
      setPendingHideFieldId(fieldId);
      return;
    }

    setVisibleOptionalFields((prev) => prev.filter((id) => id !== fieldId));
  };

  const resolveHideOptionalField = (clearValue) => {
    if (!pendingHideFieldId) {
      return;
    }

    const hideFieldId = pendingHideFieldId;

    if (clearValue) {
      setForm((prev) => clearOptionalFieldValue(prev, hideFieldId));
    }

    setVisibleOptionalFields((prev) => prev.filter((id) => id !== hideFieldId));
    setPendingHideFieldId(null);
  };

  const cancelHideOptionalField = () => {
    setPendingHideFieldId(null);
  };

  const addSelectedOptionalField = () => {
    if (!fieldToAdd) {
      return;
    }

    showOptionalField(fieldToAdd);
    setFieldSearchTerm("");
    setFieldPickerOpen(false);
  };

  const toggleSection = (sectionId) => {
    setOpenSections((prev) => ({ ...prev, [sectionId]: !prev[sectionId] }));
  };

  const isOptionalFieldVisible = (fieldId) =>
    visibleOptionalFields.includes(fieldId);
  const hasRequiredContactIdentity =
    hasTextValue(form.first_name) ||
    hasTextValue(form.last_name) ||
    hasTextValue(form.company);
  const selectedAddressBookCount = Array.isArray(form.address_book_ids)
    ? form.address_book_ids.length
    : 0;
  const pendingHideFieldLabel =
    optionalFieldsWithLabels.find((field) => field.id === pendingHideFieldId)
      ?.resolvedLabel ?? pendingHideFieldId;

  return {
    loading,
    submitting,
    error,
    queueStatusNotice,
    contacts,
    addressBooks,
    selectedContactId,
    form,
    openSections,
    hiddenOptionalFields,
    filteredHiddenOptionalFields,
    filteredContacts,
    paginatedContacts,
    contactSearchTerm,
    setContactSearchTerm,
    contactAddressBookFilter,
    setContactAddressBookFilter,
    currentContactPage,
    totalContactPages,
    firstContactIndex,
    lastContactIndex,
    hasContactFilters,
    setContactsPage,
    selectedAddressBookCount,
    hasRequiredContactIdentity,
    pendingHideFieldId,
    pendingHideFieldLabel,
    fieldSearchTerm,
    setFieldSearchTerm,
    fieldPickerOpen,
    setFieldPickerOpen,
    fieldToAdd,
    setFieldToAdd,
    visibleOptionalFields,
    setForm,
    photoConstraints,
    labelOptions,
    categoryOptions,
    relatedNameOptions,
    saveContact,
    removeContact,
    stageContactPhotoUpload,
    removePhotoFromForm,
    undoPhotoRemoval,
    clearPendingPhotoUpload,
    startNewContact,
    selectContact,
    updateFormField,
    updateBirthdayField,
    toggleAssignedAddressBook,
    showOptionalField,
    hideOptionalField,
    addSelectedOptionalField,
    toggleSection,
    isOptionalFieldVisible,
    cancelHideOptionalField,
    resolveHideOptionalField,
  };
}
