import React from "react";
import { useTranslation } from "react-i18next";
import ContactEditorAddressBooksSection from "./ContactEditorAddressBooksSection";
import ContactEditorCommunicationSection from "./ContactEditorCommunicationSection";
import ContactEditorNameSection from "./ContactEditorNameSection";
import ContactEditorOptionalFieldsSection from "./ContactEditorOptionalFieldsSection";
import ContactEditorPersonalSection from "./ContactEditorPersonalSection";
import ContactEditorPhotoSection from "./ContactEditorPhotoSection";
import ContactEditorWorkSection from "./ContactEditorWorkSection";

/**
 * Renders the Contact Editor Panel.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactEditorPanel({
  form,
  submitting,
  addressBooks,
  selectedAddressBookCount,
  photoConstraints,
  hasRequiredContactIdentity,
  saveContact,
  removeContact,
  stageContactPhotoUpload,
  removePhotoFromForm,
  undoPhotoRemoval,
  clearPendingPhotoUpload,
  openSections,
  toggleSection,
  isOptionalFieldVisible,
  Field,
  updateFormField,
  PRONOUN_OPTIONS,
  showOptionalField,
  updateBirthdayField,
  DateEditor,
  LabeledValueEditor,
  AddressEditor,
  RelatedNameEditor,
  CategoryTagEditor,
  labelOptions,
  categoryOptions,
  relatedNameOptions,
  setForm,
  hiddenOptionalFields,
  fieldSearchTerm,
  setFieldSearchTerm,
  fieldPickerOpen,
  setFieldPickerOpen,
  addSelectedOptionalField,
  filteredHiddenOptionalFields,
  fieldToAdd,
  setFieldToAdd,
  visibleOptionalFields,
  hideOptionalField,
  OPTIONAL_CONTACT_FIELDS,
  toggleAssignedAddressBook,
}) {
  const { t } = useTranslation("contacts");
  const saveDisabled =
    submitting ||
    addressBooks.length === 0 ||
    selectedAddressBookCount === 0 ||
    !hasRequiredContactIdentity;

  return (
    <section className="surface min-w-0 rounded-3xl p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-app-strong">
            {form.id ? t("editor.edit") : t("editor.new")}
          </h2>
          <p className="mt-1 text-sm text-app-muted">
            {t("editor.description")}
          </p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
          {form.id ? (
            <button
              className="btn-outline btn-outline-sm w-full text-app-danger sm:w-auto"
              type="button"
              onClick={removeContact}
              disabled={submitting}
            >
              {t("editor.delete")}
            </button>
          ) : null}
          <button
            className="btn w-full sm:w-auto"
            type="submit"
            form="contact-editor"
            disabled={saveDisabled}
          >
            {submitting ? t("editor.saving") : t("editor.save")}
          </button>
        </div>
      </div>

      {addressBooks.length === 0 ? (
        <p className="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {t("editor.noAddressBooks")}
        </p>
      ) : null}

      <form
        id="contact-editor"
        className="mt-5 space-y-6"
        onSubmit={saveContact}
      >
        <ContactEditorNameSection
          isOpen={openSections.name}
          onToggle={() => toggleSection("name")}
          form={form}
          Field={Field}
          isOptionalFieldVisible={isOptionalFieldVisible}
          updateFormField={updateFormField}
        />

        <ContactEditorPhotoSection
          photo={form.photo}
          photoUploadToken={form.photo_upload_token}
          photoRemove={form.photo_remove}
          constraints={photoConstraints}
          submitting={submitting}
          onStagePhotoUpload={stageContactPhotoUpload}
          onRemovePhoto={removePhotoFromForm}
          onUndoPhotoRemoval={undoPhotoRemoval}
          onClearPendingUpload={clearPendingPhotoUpload}
        />

        <ContactEditorWorkSection
          isOpen={openSections.work}
          onToggle={() => toggleSection("work")}
          form={form}
          Field={Field}
          isOptionalFieldVisible={isOptionalFieldVisible}
          updateFormField={updateFormField}
        />

        <ContactEditorPersonalSection
          isOpen={openSections.personal}
          onToggle={() => toggleSection("personal")}
          form={form}
          Field={Field}
          isOptionalFieldVisible={isOptionalFieldVisible}
          updateFormField={updateFormField}
          PRONOUN_OPTIONS={PRONOUN_OPTIONS}
          showOptionalField={showOptionalField}
          updateBirthdayField={updateBirthdayField}
          DateEditor={DateEditor}
          labelOptions={labelOptions}
          RelatedNameEditor={RelatedNameEditor}
          CategoryTagEditor={CategoryTagEditor}
          categoryOptions={categoryOptions}
          relatedNameOptions={relatedNameOptions}
          setForm={setForm}
        />

        <ContactEditorCommunicationSection
          isOpen={openSections.communication}
          onToggle={() => toggleSection("communication")}
          form={form}
          updateFormField={updateFormField}
          labelOptions={labelOptions}
          isOptionalFieldVisible={isOptionalFieldVisible}
          LabeledValueEditor={LabeledValueEditor}
          AddressEditor={AddressEditor}
        />

        <ContactEditorOptionalFieldsSection
          hiddenOptionalFields={hiddenOptionalFields}
          fieldSearchTerm={fieldSearchTerm}
          setFieldSearchTerm={setFieldSearchTerm}
          fieldPickerOpen={fieldPickerOpen}
          setFieldPickerOpen={setFieldPickerOpen}
          addSelectedOptionalField={addSelectedOptionalField}
          filteredHiddenOptionalFields={filteredHiddenOptionalFields}
          fieldToAdd={fieldToAdd}
          setFieldToAdd={setFieldToAdd}
          visibleOptionalFields={visibleOptionalFields}
          hideOptionalField={hideOptionalField}
          OPTIONAL_CONTACT_FIELDS={OPTIONAL_CONTACT_FIELDS}
        />

        <ContactEditorAddressBooksSection
          isOpen={openSections.addressBooks}
          onToggle={() => toggleSection("addressBooks")}
          selectedAddressBookCount={selectedAddressBookCount}
          addressBooks={addressBooks}
          form={form}
          toggleAssignedAddressBook={toggleAssignedAddressBook}
        />

        <section className="sticky bottom-2 z-20 sm:bottom-3">
          <div className="surface flex w-full max-w-full flex-wrap items-center justify-end gap-1.5 rounded-xl px-2.5 py-1.5 shadow-lg shadow-black/10 sm:gap-2 sm:rounded-2xl sm:px-3 sm:py-2">
            {form.id ? (
              <button
                className="btn-outline btn-outline-sm text-app-danger"
                type="button"
                onClick={removeContact}
                disabled={submitting}
              >
                {t("editor.delete")}
              </button>
            ) : null}
            <button
              className="btn !px-3 !py-1.5 sm:!px-4 sm:!py-2"
              type="submit"
              disabled={saveDisabled}
            >
              {submitting ? t("editor.saving") : t("editor.save")}
            </button>
          </div>
        </section>
      </form>
    </section>
  );
}
