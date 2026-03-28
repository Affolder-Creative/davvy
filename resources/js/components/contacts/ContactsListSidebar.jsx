import React from "react";
import { useTranslation } from "react-i18next";

function contactInitials(displayName) {
  const parts = String(displayName ?? "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length === 0) {
    return "?";
  }

  if (parts.length === 1) {
    return parts[0].slice(0, 1).toUpperCase();
  }

  return `${parts[0].slice(0, 1)}${parts[1].slice(0, 1)}`.toUpperCase();
}

/**
 * Renders the Contacts List Sidebar component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactsListSidebar({
  contacts,
  filteredContacts,
  paginatedContacts,
  addressBooks,
  contactSearchTerm,
  onContactSearchTermChange,
  contactAddressBookFilter,
  onContactAddressBookFilterChange,
  selectedContactId,
  onSelectContact,
  onStartNewContact,
  hasContactFilters,
  onClearFilters,
  contactsPageSize,
  firstContactIndex,
  lastContactIndex,
  currentContactPage,
  totalContactPages,
  setContactsPage,
}) {
  const { t } = useTranslation("contacts");
  return (
    <aside className="surface h-fit rounded-3xl p-4">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-app-base">
          {t("sidebar.title")}
        </h2>
        <button
          className="btn-outline btn-outline-sm"
          onClick={onStartNewContact}
        >
          {t("sidebar.new")}
        </button>
      </div>
      <div className="mt-3 space-y-2">
        <input
          className="input"
          type="search"
          placeholder={t("sidebar.searchPlaceholder")}
          value={contactSearchTerm}
          onChange={(event) => onContactSearchTermChange(event.target.value)}
        />
        <select
          className="input"
          value={contactAddressBookFilter}
          onChange={(event) =>
            onContactAddressBookFilterChange(event.target.value)
          }
        >
          <option value="all">{t("sidebar.filterAllOption")}</option>
          {addressBooks.map((book) => (
            <option key={book.id} value={String(book.id)}>
              {book.display_name}
            </option>
          ))}
        </select>
      </div>
      <div className="mt-2 flex items-center justify-between gap-2 text-xs text-app-faint">
        <span>
          {t("sidebar.matchCount", {
            count: filteredContacts.length,
          })}
        </span>
        {hasContactFilters ? (
          <button
            className="text-xs font-semibold text-app-accent hover:text-app-accent-strong"
            type="button"
            onClick={onClearFilters}
          >
            {t("sidebar.clearFilters")}
          </button>
        ) : null}
      </div>
      <div className="mt-3 space-y-2">
        {contacts.length === 0 ? (
          <p className="text-sm text-app-faint">{t("sidebar.noContacts")}</p>
        ) : filteredContacts.length === 0 ? (
          <p className="text-sm text-app-faint">
            {t("sidebar.noContactsMatchFilter")}
          </p>
        ) : (
          paginatedContacts.map((contact) => {
            const addressBookCount = Array.isArray(contact.address_books)
              ? contact.address_books.length
              : 0;
            const thumbnailUrl = String(
              contact?.photo?.thumbnail_url ?? contact?.photo?.url ?? "",
            ).trim();
            const initials = contactInitials(contact.display_name);

            return (
              <button
                key={contact.id}
                type="button"
                className={`w-full rounded-xl border px-3 py-2 text-left transition ${
                  selectedContactId === contact.id
                    ? "border-app-accent-edge bg-app-surface text-app-strong ring-1 ring-teal-500/30"
                    : "border-app-edge bg-app-surface text-app-base hover:border-app-accent-edge"
                }`}
                onClick={() => onSelectContact(contact)}
              >
                <div className="flex items-center gap-3">
                  <div className="h-9 w-9 shrink-0 overflow-hidden rounded-full border border-app-edge bg-app-panel">
                    {thumbnailUrl ? (
                      <img
                        src={thumbnailUrl}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-app-faint">
                        {initials}
                      </div>
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                      {contact.display_name}
                    </p>
                    <p className="mt-1 text-xs text-app-faint">
                      {t("sidebar.addressBookCount", {
                        count: addressBookCount,
                        plural: addressBookCount > 1 ? "s" : "",
                      })}
                    </p>
                  </div>
                </div>
              </button>
            );
          })
        )}
      </div>
      {filteredContacts.length > contactsPageSize ? (
        <div className="mt-3 rounded-xl border border-app-edge px-2 py-2">
          <div className="flex items-center justify-between gap-2 text-[11px] text-app-faint">
            <span>
              {firstContactIndex + 1}-{lastContactIndex} of{" "}
              {filteredContacts.length}
            </span>
            <span>
              {t("sidebar.page", {
                current: currentContactPage,
                total: totalContactPages,
              })}
            </span>
          </div>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <button
              className="btn-outline btn-outline-sm w-full"
              type="button"
              onClick={() =>
                setContactsPage((prevPage) => Math.max(1, prevPage - 1))
              }
              disabled={currentContactPage === 1}
            >
              {t("sidebar.prevPage")}
            </button>
            <button
              className="btn-outline btn-outline-sm w-full"
              type="button"
              onClick={() =>
                setContactsPage((prevPage) =>
                  Math.min(totalContactPages, prevPage + 1),
                )
              }
              disabled={currentContactPage >= totalContactPages}
            >
              {t("sidebar.nextPage")}
            </button>
          </div>
        </div>
      ) : null}
    </aside>
  );
}
