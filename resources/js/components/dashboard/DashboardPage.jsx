import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import DashboardAppleCompatPanelComponent from "./DashboardAppleCompatPanel";
import DashboardOverviewCardsComponent from "./DashboardOverviewCards";
import DashboardSharingPanelComponent from "./DashboardSharingPanel";

/**
 * Renders the Dashboard Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function DashboardPage({
  auth,
  theme,
  api,
  extractError,
  downloadExport,
  fileStem,
  AppShell,
  FullPageState,
  InfoCard,
  PermissionBadge,
  ResourcePanel,
  AddressBookMilestoneControls,
  DashboardOverviewCards = DashboardOverviewCardsComponent,
  DashboardSharingPanel = DashboardSharingPanelComponent,
  DashboardAppleCompatPanel = DashboardAppleCompatPanelComponent,
}) {
  const { t } = useTranslation("dashboard");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [shareStatusNotice, setShareStatusNotice] = useState("");
  const [data, setData] = useState({
    owned: { calendars: [], address_books: [] },
    shared: { calendars: [], address_books: [] },
    sharing: { can_manage: false, targets: [], outgoing: [] },
    apple_compat: {
      enabled: false,
      target_address_book_id: null,
      target_address_book_uri: null,
      target_display_name: null,
      selected_source_ids: [],
      source_options: [],
    },
  });
  const [appleCompatForm, setAppleCompatForm] = useState({
    enabled: false,
    source_ids: [],
  });
  const [calendarForm, setCalendarForm] = useState({
    display_name: "",
    is_sharable: false,
  });
  const [bookForm, setBookForm] = useState({
    display_name: "",
    is_sharable: false,
  });
  const [shareForm, setShareForm] = useState({
    resource_type: "calendar",
    resource_id: "",
    shared_with_id: "",
    permission: "read_only",
  });

  const loadDashboard = async ({ withLoading = true } = {}) => {
    if (withLoading) {
      setLoading(true);
    }
    setError("");
    try {
      const response = await api.get("/api/dashboard");
      const payload = response.data;
      setData(payload);
      setAppleCompatForm({
        enabled: !!payload.apple_compat?.enabled,
        source_ids: payload.apple_compat?.selected_source_ids ?? [],
      });
    } catch (err) {
      setError(extractError(err, t("errors.load")));
    } finally {
      if (withLoading) {
        setLoading(false);
      }
    }
  };

  useEffect(() => {
    loadDashboard();
  }, []);

  useEffect(() => {
    if (!shareStatusNotice) {
      return undefined;
    }

    const timer = window.setTimeout(() => setShareStatusNotice(""), 2200);
    return () => window.clearTimeout(timer);
  }, [shareStatusNotice]);

  const toggleSharable = async (type, id, next, displayName) => {
    const url =
      type === "calendar" ? `/api/calendars/${id}` : `/api/address-books/${id}`;
    try {
      await api.patch(url, { is_sharable: next });
      setShareStatusNotice(
        next
          ? t("notices.shareStatus.shared", { name: displayName })
          : t("notices.shareStatus.unshared", { name: displayName }),
      );
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(extractError(err, t("errors.updateShareStatus")));
    }
  };

  const renameOwnedResource = async (type, id, displayName) => {
    const url =
      type === "calendar" ? `/api/calendars/${id}` : `/api/address-books/${id}`;

    try {
      // Keep DAV collection URL stable by updating only the display name.
      await api.patch(url, { display_name: displayName });
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(extractError(err, t("errors.renameResource")));
      throw err;
    }
  };

  const deleteOwnedResource = async (type, item) => {
    const confirmed = window.confirm(
      t("resourcePanel.deleteConfirm", {
        name: item.display_name,
      }),
    );

    if (!confirmed) {
      return;
    }

    const url =
      type === "calendar"
        ? `/api/calendars/${item.id}`
        : `/api/address-books/${item.id}`;

    try {
      setError("");
      await api.delete(url);
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(
        extractError(
          err,
          type === "calendar"
            ? t("errors.deleteCalendar")
            : t("errors.deleteAddressBook"),
        ),
      );
    }
  };

  const createCalendar = async (event) => {
    event.preventDefault();
    try {
      await api.post("/api/calendars", calendarForm);
      setCalendarForm({ display_name: "", is_sharable: false });
      await loadDashboard();
    } catch (err) {
      setError(extractError(err, t("errors.createCalendar")));
    }
  };

  const createAddressBook = async (event) => {
    event.preventDefault();
    try {
      await api.post("/api/address-books", bookForm);
      setBookForm({ display_name: "", is_sharable: false });
      await loadDashboard();
    } catch (err) {
      setError(extractError(err, t("errors.createAddressBook")));
    }
  };

  const saveShare = async (event) => {
    event.preventDefault();
    try {
      await api.post("/api/shares", {
        ...shareForm,
        resource_id: Number(shareForm.resource_id),
        shared_with_id: Number(shareForm.shared_with_id),
      });
      setShareForm((prev) => ({
        ...prev,
        resource_id: "",
        shared_with_id: "",
      }));
      await loadDashboard();
    } catch (err) {
      setError(extractError(err, t("errors.saveShare")));
    }
  };

  const deleteShare = async (shareId) => {
    try {
      await api.delete(`/api/shares/${shareId}`);
      await loadDashboard();
    } catch (err) {
      setError(extractError(err, t("errors.removeShare")));
    }
  };

  const runExport = async (url, fallbackName, fallbackMessage) => {
    try {
      setError("");
      await downloadExport(url, fallbackName);
    } catch (err) {
      setError(
        err instanceof Error && err.message ? err.message : fallbackMessage,
      );
    }
  };

  const saveAppleCompat = async (event) => {
    event.preventDefault();
    try {
      setError("");
      await api.patch("/api/address-books/apple-compat", appleCompatForm);
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(extractError(err, t("errors.appleCompat")));
    }
  };

  const saveAddressBookMilestones = async (addressBookId, payload) => {
    try {
      setError("");
      await api.patch(
        `/api/address-books/${addressBookId}/milestone-calendars`,
        payload,
      );
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(
        extractError(
          err,
          t("errors.milestones"),
        ),
      );
      throw err;
    }
  };

  const deleteMilestoneCalendar = async (calendar) => {
    try {
      setError("");
      await api.delete(`/api/calendars/${calendar.id}`);
      await loadDashboard({ withLoading: false });
    } catch (err) {
      setError(extractError(err, t("errors.deleteCalendar")));
      throw err;
    }
  };

  const shareableResourceOptions =
    shareForm.resource_type === "calendar"
      ? data.owned.calendars.filter((item) => item.is_sharable)
      : data.owned.address_books.filter((item) => item.is_sharable);
  const canSelectAppleCompatSources =
    !!data.apple_compat.target_address_book_id && appleCompatForm.enabled;

  return (
    <AppShell auth={auth} theme={theme}>
      {shareStatusNotice ? (
        <div className="pointer-events-none fixed inset-x-0 top-4 z-50 flex justify-center px-4">
          <p className="rounded-xl border border-app-accent-edge bg-teal-700/95 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-teal-900/20 backdrop-blur">
            {shareStatusNotice}
          </p>
        </div>
      ) : null}
      <DashboardOverviewCards auth={auth} InfoCard={InfoCard} />

      {error ? (
        <div className="surface mt-4 rounded-2xl p-3 text-sm text-app-danger">
          {error}
        </div>
      ) : null}
      {loading ? <FullPageState label={t("states.loadingResources")} compact /> : null}

      {!loading ? (
        <div className="mt-6 grid gap-6 lg:grid-cols-2">
          <ResourcePanel
            title={t("resources.yourCalendars")}
            createLabel={t("resources.createCalendar")}
            exportAllLabel={t("resources.exportAll")}
            resourceKind="calendar"
            principalId={auth.user.id}
            items={data.owned.calendars}
            sharedItems={data.shared.calendars}
            onCreate={createCalendar}
            form={calendarForm}
            setForm={setCalendarForm}
            onExportAll={() =>
              runExport(
                "/api/exports/calendars",
                "davvy-calendars.zip",
                t("errors.exportCalendars"),
              )
            }
            onExportItem={(item) =>
              runExport(
                `/api/exports/calendars/${item.id}`,
                `${fileStem(item.display_name, "calendar")}.ics`,
                t("errors.exportCalendar"),
              )
            }
            onToggle={(id, next, displayName) =>
              toggleSharable("calendar", id, next, displayName)
            }
            onRename={(id, displayName) =>
              renameOwnedResource("calendar", id, displayName)
            }
            onDelete={(item) => deleteOwnedResource("calendar", item)}
          />
          <ResourcePanel
            title={t("resources.yourAddressBooks")}
            createLabel={t("resources.createAddressBook")}
            exportAllLabel={t("resources.exportAll")}
            resourceKind="address-book"
            principalId={auth.user.id}
            items={data.owned.address_books}
            sharedItems={data.shared.address_books}
            onCreate={createAddressBook}
            form={bookForm}
            setForm={setBookForm}
            onExportAll={() =>
              runExport(
                "/api/exports/address-books",
                "davvy-address-books.zip",
                t("errors.exportAddressBooks"),
              )
            }
            onExportItem={(item) =>
              runExport(
                `/api/exports/address-books/${item.id}`,
                `${fileStem(item.display_name, "address-book")}.vcf`,
                t("errors.exportAddressBook"),
              )
            }
            onToggle={(id, next, displayName) =>
              toggleSharable("address-book", id, next, displayName)
            }
            onRename={(id, displayName) =>
              renameOwnedResource("address-book", id, displayName)
            }
            onDelete={(item) => deleteOwnedResource("address-book", item)}
            renderOwnedItemExtra={(item) => (
              <AddressBookMilestoneControls
                item={item}
                onSave={saveAddressBookMilestones}
                onDeleteCalendar={deleteMilestoneCalendar}
              />
            )}
          />
        </div>
      ) : null}

      {!loading && data.sharing.can_manage ? (
        <DashboardSharingPanel
          shareForm={shareForm}
          setShareForm={setShareForm}
          shareableResourceOptions={shareableResourceOptions}
          targets={data.sharing.targets}
          outgoing={data.sharing.outgoing}
          onSaveShare={saveShare}
          onDeleteShare={deleteShare}
          PermissionBadge={PermissionBadge}
        />
      ) : null}

      {!loading ? (
        <DashboardAppleCompatPanel
          appleCompat={data.apple_compat}
          appleCompatForm={appleCompatForm}
          setAppleCompatForm={setAppleCompatForm}
          canSelectAppleCompatSources={canSelectAppleCompatSources}
          onSaveAppleCompat={saveAppleCompat}
        />
      ) : null}
    </AppShell>
  );
}
