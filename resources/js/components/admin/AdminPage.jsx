import React, { useEffect, useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { setI18nLocale } from "../../i18n";
import { useToast } from "../common/ToastProvider";

/**
 * Renders the Admin Page.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function AdminPage({
  auth,
  theme,
  api,
  extractError,
  AppShell,
  InfoCard,
  AdminFeatureToggle,
  FullPageState,
  Field,
  PermissionBadge,
  CheckIcon,
  buildTimezoneGroups,
  parseBackupScheduleTimes,
  isRecommendedBackupRetention,
  areBackupConfigSnapshotsEqual,
  formatAdminTimestamp,
  MILESTONE_PURGE_SUMMARY_AUTO_HIDE_MS,
  BACKUP_DRAWER_ANIMATION_MS,
  WEEKDAY_OPTIONS,
  MONTH_OPTIONS,
  RECOMMENDED_BACKUP_RETENTION,
}) {
  const { t } = useTranslation("admin");
  const { showToast } = useToast();
  const [state, setState] = useState({
    loading: true,
    users: [],
    shares: [],
    resources: { calendars: [], address_books: [] },
    error: "",
    registrationEnabled: auth.registrationEnabled,
    registrationApprovalRequired: auth.registrationApprovalRequired,
    ownerShareManagementEnabled: auth.ownerShareManagementEnabled,
    davCompatibilityModeEnabled: auth.davCompatibilityModeEnabled,
    contactManagementEnabled: auth.contactManagementEnabled,
    contactChangeModerationEnabled: auth.contactChangeModerationEnabled,
    twoFactorEnforcementEnabled: auth.twoFactorEnforcementEnabled,
    contactChangeRetentionDays: 90,
    milestoneGenerationYears: 3,
    milestonePurgeVisible: false,
    milestonePurgeAvailable: false,
    backupEnabled: false,
    backupLocalEnabled: true,
    backupLocalPath: "",
    backupS3Enabled: false,
    backupS3Disk: "s3",
    backupS3Prefix: "davvy-backups",
    backupTimezone: "UTC",
    backupScheduleTimes: "02:30",
    backupWeeklyDay: 0,
    backupMonthlyDay: 1,
    backupYearlyMonth: 1,
    backupYearlyDay: 1,
    backupRetentionDaily: 7,
    backupRetentionWeekly: 4,
    backupRetentionMonthly: 12,
    backupRetentionYearly: 3,
    backupLastRunAt: null,
    backupLastRunStatus: null,
    backupLastRunMessage: "",
  });
  const [userForm, setUserForm] = useState({
    name: "",
    email: "",
    role: "regular",
  });
  const [userInviteResult, setUserInviteResult] = useState(null);
  const [shareForm, setShareForm] = useState({
    resource_type: "calendar",
    resource_id: "",
    shared_with_id: "",
    permission: "read_only",
  });
  const [deleteUserTarget, setDeleteUserTarget] = useState(null);
  const [deleteUserConfirmationEmail, setDeleteUserConfirmationEmail] =
    useState("");
  const [deleteUserTransferOwnerId, setDeleteUserTransferOwnerId] =
    useState("");
  const [deleteUserSubmitting, setDeleteUserSubmitting] = useState(false);
  const [milestonePurgeSubmitting, setMilestonePurgeSubmitting] =
    useState(false);
  const [milestonePurgeSummary, setMilestonePurgeSummary] = useState("");
  const [retentionSubmitting, setRetentionSubmitting] = useState(false);
  const [milestoneGenerationSubmitting, setMilestoneGenerationSubmitting] =
    useState(false);
  const [backupSaving, setBackupSaving] = useState(false);
  const [backupRunning, setBackupRunning] = useState(false);
  const [backupRestoring, setBackupRestoring] = useState(false);
  const [backupRestoreMode, setBackupRestoreMode] = useState("merge");
  const [backupRestoreDryRun, setBackupRestoreDryRun] = useState(false);
  const [backupRestoreFile, setBackupRestoreFile] = useState(null);
  const [backupRestoreResult, setBackupRestoreResult] = useState(null);
  const [backupRestoreOpen, setBackupRestoreOpen] = useState(false);
  const [backupRestoreRendered, setBackupRestoreRendered] = useState(false);
  const [backupConfigOpen, setBackupConfigOpen] = useState(false);
  const [backupConfigRendered, setBackupConfigRendered] = useState(false);
  const [backupAdvancedOpen, setBackupAdvancedOpen] = useState(false);
  const [backupRetentionPreset, setBackupRetentionPreset] =
    useState("recommended");
  const backupConfigOpenFrameRef = useRef(null);
  const backupRestoreOpenFrameRef = useRef(null);
  const backupConfigSnapshotRef = useRef(null);

  const captureBackupConfigSnapshot = () => ({
    backupEnabled: state.backupEnabled,
    backupLocalEnabled: state.backupLocalEnabled,
    backupLocalPath: state.backupLocalPath,
    backupS3Enabled: state.backupS3Enabled,
    backupS3Disk: state.backupS3Disk,
    backupS3Prefix: state.backupS3Prefix,
    backupTimezone: state.backupTimezone,
    backupScheduleTimes: state.backupScheduleTimes,
    backupWeeklyDay: state.backupWeeklyDay,
    backupMonthlyDay: state.backupMonthlyDay,
    backupYearlyMonth: state.backupYearlyMonth,
    backupYearlyDay: state.backupYearlyDay,
    backupRetentionDaily: state.backupRetentionDaily,
    backupRetentionWeekly: state.backupRetentionWeekly,
    backupRetentionMonthly: state.backupRetentionMonthly,
    backupRetentionYearly: state.backupRetentionYearly,
    backupRetentionPreset,
  });

  const restoreBackupConfigSnapshot = (snapshot) => {
    if (!snapshot) {
      return;
    }

    const { backupRetentionPreset: nextRetentionPreset, ...snapshotState } =
      snapshot;

    setBackupRetentionPreset(nextRetentionPreset);
    setState((prev) => ({
      ...prev,
      ...snapshotState,
    }));
  };

  const closeBackupConfigDrawer = ({ discardChanges = true } = {}) => {
    if (backupConfigOpenFrameRef.current !== null) {
      window.cancelAnimationFrame(backupConfigOpenFrameRef.current);
      backupConfigOpenFrameRef.current = null;
    }

    if (discardChanges) {
      restoreBackupConfigSnapshot(backupConfigSnapshotRef.current);
    }

    backupConfigSnapshotRef.current = null;
    setBackupAdvancedOpen(false);
    setBackupConfigOpen(false);
  };

  const resetBackupRestoreForm = () => {
    setBackupRestoreMode("merge");
    setBackupRestoreDryRun(false);
    setBackupRestoreFile(null);
    setBackupRestoreResult(null);
  };

  const closeBackupRestoreDrawer = () => {
    if (backupRestoreOpenFrameRef.current !== null) {
      window.cancelAnimationFrame(backupRestoreOpenFrameRef.current);
      backupRestoreOpenFrameRef.current = null;
    }

    setBackupRestoreOpen(false);
  };

  const openBackupConfigDrawer = () => {
    if (backupConfigOpenFrameRef.current !== null) {
      window.cancelAnimationFrame(backupConfigOpenFrameRef.current);
      backupConfigOpenFrameRef.current = null;
    }

    backupConfigSnapshotRef.current = captureBackupConfigSnapshot();
    setBackupAdvancedOpen(false);
    setBackupConfigRendered(true);
    setBackupConfigOpen(false);

    backupConfigOpenFrameRef.current = window.requestAnimationFrame(() => {
      backupConfigOpenFrameRef.current = null;
      setBackupConfigOpen(true);
    });
  };

  const openBackupRestoreDrawer = () => {
    if (backupRestoreOpenFrameRef.current !== null) {
      window.cancelAnimationFrame(backupRestoreOpenFrameRef.current);
      backupRestoreOpenFrameRef.current = null;
    }

    resetBackupRestoreForm();
    setBackupRestoreRendered(true);
    setBackupRestoreOpen(false);

    backupRestoreOpenFrameRef.current = window.requestAnimationFrame(() => {
      backupRestoreOpenFrameRef.current = null;
      setBackupRestoreOpen(true);
    });
  };

  const load = async () => {
    setState((prev) => ({ ...prev, loading: true, error: "" }));

    try {
      const [
        users,
        resources,
        shares,
        retention,
        milestoneGeneration,
        backupSettings,
      ] = await Promise.all([
        api.get("/api/admin/users"),
        api.get("/api/admin/resources"),
        api.get("/api/admin/shares"),
        api.get("/api/admin/settings/contact-change-retention"),
        api.get("/api/admin/settings/milestone-generation-years"),
        api.get("/api/admin/settings/backups"),
      ]);

      const backup = backupSettings.data ?? {};
      const lastRun = backup.last_run ?? {};
      const backupRetentionDaily = Number(backup.retention_daily ?? 7);
      const backupRetentionWeekly = Number(backup.retention_weekly ?? 4);
      const backupRetentionMonthly = Number(backup.retention_monthly ?? 12);
      const backupRetentionYearly = Number(backup.retention_yearly ?? 3);

      setBackupRetentionPreset(
        isRecommendedBackupRetention({
          daily: backupRetentionDaily,
          weekly: backupRetentionWeekly,
          monthly: backupRetentionMonthly,
          yearly: backupRetentionYearly,
        })
          ? "recommended"
          : "custom",
      );

      setState((prev) => ({
        ...prev,
        loading: false,
        users: users.data.data,
        resources: resources.data,
        shares: shares.data.data,
        contactChangeRetentionDays: Number(retention.data?.days || 90),
        milestoneGenerationYears: Number(milestoneGeneration.data?.years || 3),
        milestonePurgeVisible: !!resources.data?.milestone_purge_visible,
        milestonePurgeAvailable: !!resources.data?.milestone_purge_available,
        backupEnabled: !!backup.enabled,
        backupLocalEnabled: !!backup.local_enabled,
        backupLocalPath: backup.local_path || "",
        backupS3Enabled: !!backup.s3_enabled,
        backupS3Disk: backup.s3_disk || "s3",
        backupS3Prefix: backup.s3_prefix || "",
        backupTimezone: backup.timezone || "UTC",
        backupScheduleTimes: Array.isArray(backup.schedule_times)
          ? backup.schedule_times.join(", ")
          : "02:30",
        backupWeeklyDay: Number(backup.weekly_day ?? 0),
        backupMonthlyDay: Number(backup.monthly_day ?? 1),
        backupYearlyMonth: Number(backup.yearly_month ?? 1),
        backupYearlyDay: Number(backup.yearly_day ?? 1),
        backupRetentionDaily,
        backupRetentionWeekly,
        backupRetentionMonthly,
        backupRetentionYearly,
        backupLastRunAt: lastRun.at || null,
        backupLastRunStatus: lastRun.status || null,
        backupLastRunMessage: lastRun.message || "",
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        loading: false,
        error: extractError(err, t("errors.loadingAdminData")),
      }));
    }
  };

  useEffect(() => {
    load();
  }, []);

  useEffect(() => {
    if (!milestonePurgeSummary) {
      return undefined;
    }

    const timer = window.setTimeout(
      () => setMilestonePurgeSummary(""),
      MILESTONE_PURGE_SUMMARY_AUTO_HIDE_MS,
    );

    return () => window.clearTimeout(timer);
  }, [milestonePurgeSummary]);

  useEffect(() => {
    if (backupConfigOpen) {
      setBackupConfigRendered(true);
      return undefined;
    }

    const timer = window.setTimeout(
      () => setBackupConfigRendered(false),
      BACKUP_DRAWER_ANIMATION_MS,
    );

    return () => window.clearTimeout(timer);
  }, [backupConfigOpen]);

  useEffect(() => {
    if (backupRestoreOpen) {
      setBackupRestoreRendered(true);
      return undefined;
    }

    const timer = window.setTimeout(
      () => setBackupRestoreRendered(false),
      BACKUP_DRAWER_ANIMATION_MS,
    );

    return () => window.clearTimeout(timer);
  }, [backupRestoreOpen]);

  useEffect(
    () => () => {
      if (backupConfigOpenFrameRef.current !== null) {
        window.cancelAnimationFrame(backupConfigOpenFrameRef.current);
      }
      if (backupRestoreOpenFrameRef.current !== null) {
        window.cancelAnimationFrame(backupRestoreOpenFrameRef.current);
      }

      backupConfigSnapshotRef.current = null;
    },
    [],
  );

  const createUser = async (event) => {
    event.preventDefault();
    setUserInviteResult(null);
    try {
      const submittedEmail = userForm.email;
      const response = await api.post("/api/admin/users", userForm);
      const created = response?.data ?? {};
      const targetEmail =
        typeof created?.email === "string" && created.email
          ? created.email
          : submittedEmail;
      setUserForm({ name: "", email: "", role: "regular" });
      setUserInviteResult({
        message: created?.invitation_sent
          ? t("notices.invitationSent", { targetEmail })
          : created?.invitation_url
            ? t("notices.invitationUrl", { targetEmail })
            : t("notices.userCreated", { targetEmail }),
        invitationUrl:
          typeof created?.invitation_url === "string"
            ? created.invitation_url
            : "",
      });
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.creatingUser")),
      }));
    }
  };

  const approveUser = async (userId) => {
    try {
      await api.patch(`/api/admin/users/${userId}/approve`);
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.approvingUser")),
      }));
    }
  };

  const openDeleteUserDialog = (user) => {
    setDeleteUserTarget(user);
    setDeleteUserConfirmationEmail("");
    setDeleteUserTransferOwnerId("");
    setState((prev) => ({ ...prev, error: "" }));
  };

  const closeDeleteUserDialog = () => {
    if (deleteUserSubmitting) {
      return;
    }

    setDeleteUserTarget(null);
    setDeleteUserConfirmationEmail("");
    setDeleteUserTransferOwnerId("");
  };

  const deleteUser = async () => {
    if (!deleteUserTarget || deleteUserSubmitting) {
      return;
    }

    const expectedEmail = String(auth.user?.email || "")
      .trim()
      .toLowerCase();
    const enteredEmail = String(deleteUserConfirmationEmail)
      .trim()
      .toLowerCase();

    if (expectedEmail === "" || enteredEmail !== expectedEmail) {
      setState((prev) => ({
        ...prev,
        error: t("errors.confirmDeletion"),
      }));
      return;
    }

    setDeleteUserSubmitting(true);
    setState((prev) => ({ ...prev, error: "" }));

    const transferOwnerId =
      deleteUserTransferOwnerId === ""
        ? null
        : Number(deleteUserTransferOwnerId);

    try {
      const response = await api.delete(
        `/api/admin/users/${deleteUserTarget.id}`,
        {
          data: {
            confirmation_email: deleteUserConfirmationEmail,
            transfer_owner_id: transferOwnerId,
          },
        },
      );

      const transferred = response.data?.transferred ?? {};
      const transferredCalendars = Number(transferred.calendars ?? 0);
      const transferredAddressBooks = Number(transferred.address_books ?? 0);
      const transferredContacts = Number(transferred.contacts ?? 0);
      const deletedUserName = deleteUserTarget.name;
      const transferTargetName = transferOwnerId
        ? state.users.find(
            (candidate) => Number(candidate.id) === transferOwnerId,
          )?.name
        : null;

      setDeleteUserTarget(null);
      setDeleteUserConfirmationEmail("");
      setDeleteUserTransferOwnerId("");
      showToast({
        status: "success",
        message: transferOwnerId
          ? t("notices.transferredData", {
              deletedUserName,
              transferredCalendars,
              transferredAddressBooks,
              transferredContacts,
              transferTargetName,
            })
          : t("notices.deletedUser", { deletedUserName }),
      });

      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToDeleteUser")),
      }));
    } finally {
      setDeleteUserSubmitting(false);
    }
  };

  const saveShare = async (event) => {
    event.preventDefault();
    try {
      await api.post("/api/admin/shares", {
        ...shareForm,
        resource_id: Number(shareForm.resource_id),
        shared_with_id: Number(shareForm.shared_with_id),
      });
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToSaveShare")),
      }));
    }
  };

  const deleteShare = async (id) => {
    try {
      await api.delete(`/api/admin/shares/${id}`);
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToDeleteShare")),
      }));
    }
  };

  const toggleRegistration = async () => {
    const next = !state.registrationEnabled;

    try {
      const response = await api.patch("/api/admin/settings/registration", {
        enabled: next,
      });
      setState((prev) => ({
        ...prev,
        registrationEnabled: !!response.data.enabled,
        registrationApprovalRequired: !!response.data.require_approval,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        registrationEnabled: !!response.data.enabled,
        registrationApprovalRequired: !!response.data.require_approval,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateRegistrationSetting"),
        ),
      }));
    }
  };

  const toggleRegistrationApproval = async () => {
    const next = !state.registrationApprovalRequired;
    let approvePending = false;

    if (!next) {
      const pendingCount = state.users.filter(
        (user) => user?.is_approved === false,
      ).length;

      if (pendingCount > 0) {
        approvePending = window.confirm(
          t("notices.approvePending", { pendingCount }),
        );
      }
    }

    try {
      const response = await api.patch(
        "/api/admin/settings/registration-approval",
        {
          enabled: next,
        },
      );
      setState((prev) => ({
        ...prev,
        registrationApprovalRequired: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        registrationApprovalRequired: !!response.data.enabled,
      }));

      if (!next && approvePending) {
        const bulkApproval = await api.patch(
          "/api/admin/users/approve-pending",
        );
        const approvedCount = Number(bulkApproval.data?.approved_count ?? 0);
        showToast({
          status: "success",
          message: t("notices.approvedPending", { approvedCount }),
        });
        await load();
      }
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateRegistrationApprovalSetting"),
        ),
      }));
    }
  };

  const toggleOwnerShareManagement = async () => {
    const next = !state.ownerShareManagementEnabled;

    try {
      const response = await api.patch(
        "/api/admin/settings/owner-share-management",
        { enabled: next },
      );
      setState((prev) => ({
        ...prev,
        ownerShareManagementEnabled: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        ownerShareManagementEnabled: !!response.data.enabled,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateOwnerShareManagementSetting"),
        ),
      }));
    }
  };

  const toggleDavCompatibilityMode = async () => {
    const next = !state.davCompatibilityModeEnabled;

    try {
      const response = await api.patch(
        "/api/admin/settings/dav-compatibility-mode",
        { enabled: next },
      );
      setState((prev) => ({
        ...prev,
        davCompatibilityModeEnabled: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        davCompatibilityModeEnabled: !!response.data.enabled,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateDavCompatibilityModeSetting"),
        ),
      }));
    }
  };

  const toggleContactManagement = async () => {
    const next = !state.contactManagementEnabled;

    try {
      const response = await api.patch(
        "/api/admin/settings/contact-management",
        {
          enabled: next,
        },
      );
      setState((prev) => ({
        ...prev,
        contactManagementEnabled: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        contactManagementEnabled: !!response.data.enabled,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateContactManagementSetting"),
        ),
      }));
    }
  };

  const toggleContactChangeModeration = async () => {
    const next = !state.contactChangeModerationEnabled;

    try {
      const response = await api.patch(
        "/api/admin/settings/contact-change-moderation",
        {
          enabled: next,
        },
      );
      setState((prev) => ({
        ...prev,
        contactChangeModerationEnabled: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        contactChangeModerationEnabled: !!response.data.enabled,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateContactChangeModerationSetting"),
        ),
      }));
    }
  };

  const toggleTwoFactorEnforcement = async () => {
    const next = !state.twoFactorEnforcementEnabled;

    try {
      const response = await api.patch(
        "/api/admin/settings/two-factor-enforcement",
        {
          enabled: next,
        },
      );
      setState((prev) => ({
        ...prev,
        twoFactorEnforcementEnabled: !!response.data.enabled,
      }));
      auth.setAuth((prev) => ({
        ...prev,
        twoFactorEnforcementEnabled: !!response.data.enabled,
      }));
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdate2faEnforcementSetting"),
        ),
      }));
    }
  };

  const resetUserTwoFactor = async (userId) => {
    const confirmed = window.confirm(t("admin.resetUser2fa"));
    if (!confirmed) {
      return;
    }

    try {
      await api.post(`/api/admin/users/${userId}/two-factor/reset`, {
        revoke_app_passwords: true,
      });
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToResetUser2fa")),
      }));
    }
  };

  const purgeGeneratedMilestoneCalendars = async () => {
    if (milestonePurgeSubmitting || !state.milestonePurgeAvailable) {
      return;
    }

    const confirmed = window.confirm(
      t("admin.purgeGeneratedMilestoneCalendarsConfirmation"),
    );
    if (!confirmed) {
      return;
    }

    setMilestonePurgeSubmitting(true);
    setMilestonePurgeSummary("");
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const response = await api.post(
        "/api/admin/contact-milestones/purge-generated-calendars",
      );
      const purgedCalendars = Number(response.data?.purged_calendar_count ?? 0);
      const purgedEvents = Number(response.data?.purged_event_count ?? 0);
      const disabledSettings = Number(
        response.data?.disabled_setting_count ?? 0,
      );
      setMilestonePurgeSummary(
        t("admin.purgeGeneratedMilestoneCalendarsSummary", {
          purgedCalendars,
          purgedEvents,
          disabledSettings,
        }),
      );
      await load();
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToPurgeGeneratedMilestoneCalendars"),
        ),
      }));
    } finally {
      setMilestonePurgeSubmitting(false);
    }
  };

  const saveContactChangeRetention = async () => {
    const days = Number(state.contactChangeRetentionDays);
    if (!Number.isFinite(days) || days < 1 || days > 3650) {
      setState((prev) => ({
        ...prev,
        error: t("errors.retentionDaysRange"),
      }));
      return;
    }

    setRetentionSubmitting(true);
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const response = await api.patch(
        "/api/admin/settings/contact-change-retention",
        {
          days,
        },
      );

      setState((prev) => ({
        ...prev,
        contactChangeRetentionDays: Number(response.data?.days || days),
      }));
      const nextDays = Number(response.data?.days || days);
      showToast({
        status: "success",
        message: t("notices.queueRetentionSaved", {
          days: nextDays,
        }),
      });
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToUpdateChangeRetention")),
      }));
    } finally {
      setRetentionSubmitting(false);
    }
  };

  const saveMilestoneGenerationYears = async () => {
    const years = Number(state.milestoneGenerationYears);
    if (!Number.isInteger(years) || years < 1 || years > 25) {
      setState((prev) => ({
        ...prev,
        error: t("errors.milestoneRangeYears"),
      }));
      return;
    }

    setMilestoneGenerationSubmitting(true);
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const response = await api.patch(
        "/api/admin/settings/milestone-generation-years",
        {
          years,
        },
      );

      setState((prev) => ({
        ...prev,
        milestoneGenerationYears: Number(response.data?.years || years),
      }));
      const nextYears = Number(response.data?.years || years);
      showToast({
        status: "success",
        message: t("notices.milestoneGenerationHorizonSaved", {
          years: nextYears,
        }),
      });
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(
          err,
          t("errors.unableToUpdateMilestoneGenerationYears"),
        ),
      }));
    } finally {
      setMilestoneGenerationSubmitting(false);
    }
  };

  const saveBackupSettings = async () => {
    const scheduleTimes = parseBackupScheduleTimes(state.backupScheduleTimes);
    const retentionDaily =
      backupRetentionPreset === "recommended"
        ? RECOMMENDED_BACKUP_RETENTION.daily
        : Number(state.backupRetentionDaily);
    const retentionWeekly =
      backupRetentionPreset === "recommended"
        ? RECOMMENDED_BACKUP_RETENTION.weekly
        : Number(state.backupRetentionWeekly);
    const retentionMonthly =
      backupRetentionPreset === "recommended"
        ? RECOMMENDED_BACKUP_RETENTION.monthly
        : Number(state.backupRetentionMonthly);
    const retentionYearly =
      backupRetentionPreset === "recommended"
        ? RECOMMENDED_BACKUP_RETENTION.yearly
        : Number(state.backupRetentionYearly);
    if (scheduleTimes.length === 0) {
      setState((prev) => ({
        ...prev,
        error: t("errors.backupScheduleTimesInvalid"),
      }));
      return;
    }

    if (
      state.backupEnabled &&
      !state.backupLocalEnabled &&
      !state.backupS3Enabled
    ) {
      setState((prev) => ({
        ...prev,
        error: t("errors.backupDestinationRequired"),
      }));
      return;
    }

    setBackupSaving(true);
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const response = await api.patch("/api/admin/settings/backups", {
        enabled: !!state.backupEnabled,
        local_enabled: !!state.backupLocalEnabled,
        local_path: state.backupLocalPath,
        s3_enabled: !!state.backupS3Enabled,
        s3_disk: state.backupS3Disk,
        s3_prefix: state.backupS3Prefix,
        schedule_times: scheduleTimes,
        timezone: state.backupTimezone,
        weekly_day: Number(state.backupWeeklyDay),
        monthly_day: Number(state.backupMonthlyDay),
        yearly_month: Number(state.backupYearlyMonth),
        yearly_day: Number(state.backupYearlyDay),
        retention_daily: retentionDaily,
        retention_weekly: retentionWeekly,
        retention_monthly: retentionMonthly,
        retention_yearly: retentionYearly,
      });

      const backup = response.data ?? {};
      const lastRun = backup.last_run ?? {};
      const nextRetentionDaily = Number(
        backup.retention_daily ?? retentionDaily,
      );
      const nextRetentionWeekly = Number(
        backup.retention_weekly ?? retentionWeekly,
      );
      const nextRetentionMonthly = Number(
        backup.retention_monthly ?? retentionMonthly,
      );
      const nextRetentionYearly = Number(
        backup.retention_yearly ?? retentionYearly,
      );

      setBackupRetentionPreset(
        isRecommendedBackupRetention({
          daily: nextRetentionDaily,
          weekly: nextRetentionWeekly,
          monthly: nextRetentionMonthly,
          yearly: nextRetentionYearly,
        })
          ? "recommended"
          : "custom",
      );

      setState((prev) => ({
        ...prev,
        backupEnabled: !!backup.enabled,
        backupLocalEnabled: !!backup.local_enabled,
        backupLocalPath: backup.local_path || "",
        backupS3Enabled: !!backup.s3_enabled,
        backupS3Disk: backup.s3_disk || "s3",
        backupS3Prefix: backup.s3_prefix || "",
        backupTimezone: backup.timezone || "UTC",
        backupScheduleTimes: Array.isArray(backup.schedule_times)
          ? backup.schedule_times.join(", ")
          : prev.backupScheduleTimes,
        backupWeeklyDay: Number(backup.weekly_day ?? 0),
        backupMonthlyDay: Number(backup.monthly_day ?? 1),
        backupYearlyMonth: Number(backup.yearly_month ?? 1),
        backupYearlyDay: Number(backup.yearly_day ?? 1),
        backupRetentionDaily: nextRetentionDaily,
        backupRetentionWeekly: nextRetentionWeekly,
        backupRetentionMonthly: nextRetentionMonthly,
        backupRetentionYearly: nextRetentionYearly,
        backupLastRunAt: lastRun.at || prev.backupLastRunAt,
        backupLastRunStatus: lastRun.status || prev.backupLastRunStatus,
        backupLastRunMessage: lastRun.message || prev.backupLastRunMessage,
      }));
      closeBackupConfigDrawer({ discardChanges: false });
    } catch (err) {
      setState((prev) => ({
        ...prev,
        error: extractError(err, t("errors.unableToSaveBackupSettings")),
      }));
    } finally {
      setBackupSaving(false);
    }
  };

  const runBackupNow = async () => {
    if (backupRunning) {
      return;
    }

    if (!state.backupLocalEnabled && !state.backupS3Enabled) {
      setState((prev) => ({
        ...prev,
        error: t("errors.backupConfigurationRequired"),
      }));
      return;
    }

    setBackupRunning(true);
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const response = await api.post("/api/admin/backups/run");
      const result = response.data ?? {};
      const nextStatus = result.status || "success";
      const nextMessage = result.reason || t("notices.backupRunSuccess");

      setState((prev) => ({
        ...prev,
        backupLastRunAt: result.executed_at_utc || prev.backupLastRunAt,
        backupLastRunStatus: nextStatus,
        backupLastRunMessage: nextMessage,
      }));
      showToast({
        status: nextStatus,
        message: nextMessage,
      });

      await load();
    } catch (err) {
      const message = extractError(err, t("errors.backupRunFailed"));
      setState((prev) => ({
        ...prev,
        error: message,
        backupLastRunStatus: "failed",
        backupLastRunMessage: message,
      }));
      showToast({
        status: "failed",
        message,
      });
    } finally {
      setBackupRunning(false);
    }
  };

  const runBackupRestore = async () => {
    if (backupRestoring) {
      return;
    }

    if (!backupRestoreFile) {
      setState((prev) => ({
        ...prev,
        error: t("errors.backupRestoreNoArchive"),
      }));
      return;
    }

    const confirmMessage = backupRestoreDryRun
      ? t("backups.dryRun.confirm")
      : backupRestoreMode === "replace"
        ? t("backups.dryRun.confirmReplace")
        : t("backups.dryRun.confirmMerge");
    if (!window.confirm(confirmMessage)) {
      return;
    }

    setBackupRestoring(true);
    setBackupRestoreResult(null);
    setState((prev) => ({ ...prev, error: "" }));

    try {
      const form = new FormData();
      form.append("backup", backupRestoreFile);
      form.append("mode", backupRestoreMode);
      form.append("dry_run", backupRestoreDryRun ? "1" : "0");

      const response = await api.post("/api/admin/backups/restore", form, {
        headers: {
          "Content-Type": "multipart/form-data",
        },
      });
      const result = response.data ?? {};

      setBackupRestoreResult(result);
      showToast({
        status: result.status || "success",
        message: result.reason || t("notices.backupRestoreCompleted"),
      });

      if (!backupRestoreDryRun) {
        await load();
      }
    } catch (err) {
      const message = extractError(err, t("errors.backupRestoreBroken"));
      setState((prev) => ({
        ...prev,
        error: message,
      }));
      showToast({
        status: "failed",
        message,
      });
    } finally {
      setBackupRestoring(false);
    }
  };

  const resourceOptions =
    shareForm.resource_type === "calendar"
      ? state.resources.calendars
      : state.resources.address_books;
  const adminConfirmationEmail = String(auth.user?.email || "").trim();
  const deleteUserTransferOptions = useMemo(() => {
    if (!deleteUserTarget) {
      return [];
    }

    return state.users.filter(
      (candidate) => Number(candidate.id) !== Number(deleteUserTarget.id),
    );
  }, [deleteUserTarget, state.users]);
  const deleteUserConfirmationMatches =
    adminConfirmationEmail !== "" &&
    String(deleteUserConfirmationEmail).trim().toLowerCase() ===
      adminConfirmationEmail.toLowerCase();
  const deleteUserActionDisabled =
    deleteUserSubmitting || !deleteUserTarget || !deleteUserConfirmationMatches;
  const backupTimezoneGroups = useMemo(() => buildTimezoneGroups(), []);
  const backupTimezoneExistsInOptions = useMemo(
    () =>
      backupTimezoneGroups.some((group) =>
        group.options.some((option) => option.value === state.backupTimezone),
      ),
    [backupTimezoneGroups, state.backupTimezone],
  );
  const backupLastRunLabel = state.backupLastRunStatus
    ? `${state.backupLastRunStatus.toUpperCase()} at ${formatAdminTimestamp(
        state.backupLastRunAt,
      )}`
    : t("states.backupLastRunNoRun");
  const backupDestinationSummary = [
    state.backupLocalEnabled ? t("labels.backupLocal") : null,
    state.backupS3Enabled ? `S3 (${state.backupS3Disk})` : null,
  ]
    .filter(Boolean)
    .join(" + ");
  const backupHasDestination = !!backupDestinationSummary;
  const backupScheduleValues = parseBackupScheduleTimes(
    state.backupScheduleTimes,
  );
  const backupScheduleSummary =
    backupScheduleValues.length === 0
      ? t("backups.noWindows", { timezone: state.backupTimezone })
      : backupScheduleValues.length <= 2
        ? `${backupScheduleValues.join(", ")} (${state.backupTimezone})`
        : t("backups.multipleWindows", {
            windowsCount: backupScheduleValues.length,
            timezone: state.backupTimezone,
          });
  const backupRetentionSummary = `${Number(state.backupRetentionDaily)}d / ${Number(
    state.backupRetentionWeekly,
  )}w / ${Number(state.backupRetentionMonthly)}m / ${Number(
    state.backupRetentionYearly,
  )}y`;
  const backupRunNowDisabled = backupRunning || !backupHasDestination;
  const backupRunNowButtonClass = backupRunNowDisabled
    ? "inline-flex items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-400"
    : "btn btn-outline-sm";
  const backupRestoreSummary = backupRestoreResult?.summary ?? null;
  const backupRestoreWarnings = Array.isArray(backupRestoreResult?.warnings)
    ? backupRestoreResult.warnings
    : [];
  const backupRestoreRunDisabled = backupRestoring || !backupRestoreFile;
  const backupRestoreRunButtonClass = backupRestoreRunDisabled
    ? "btn-outline btn-outline-sm"
    : "btn btn-outline-sm";
  const backupConfigHasUnsavedChanges =
    !!backupConfigSnapshotRef.current &&
    !areBackupConfigSnapshotsEqual(
      captureBackupConfigSnapshot(),
      backupConfigSnapshotRef.current,
    );
  const backupSaveButtonClass = backupConfigHasUnsavedChanges
    ? "btn btn-outline-sm"
    : "btn-outline btn-outline-sm";

  return (
    <AppShell auth={auth} theme={theme}>
      <div className="surface fade-up rounded-3xl p-6">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-2xl font-bold">{t("controlPanel.title")}</h2>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          <AdminFeatureToggle
            label={t("controlPanel.toggle.publicRegistration")}
            enabled={state.registrationEnabled}
            onClick={toggleRegistration}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.registrationApproval")}
            enabled={state.registrationApprovalRequired}
            onClick={toggleRegistrationApproval}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.ownerShareManagement")}
            enabled={state.ownerShareManagementEnabled}
            onClick={toggleOwnerShareManagement}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.davCompatibilityMode")}
            enabled={state.davCompatibilityModeEnabled}
            onClick={toggleDavCompatibilityMode}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.contactManagement")}
            enabled={state.contactManagementEnabled}
            onClick={toggleContactManagement}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.reviewQueue")}
            enabled={state.contactChangeModerationEnabled}
            onClick={toggleContactChangeModeration}
          />
          <AdminFeatureToggle
            label={t("controlPanel.toggle.2faEnforcement")}
            enabled={state.twoFactorEnforcementEnabled}
            onClick={toggleTwoFactorEnforcement}
          />
        </div>
        <div className="mt-4">
          <Field label={t("controlPanel.queueRetention")}>
            <p className="mb-2 text-xs text-app-faint">
              {t("controlPanel.queueRetentionDaysDescription")}
            </p>
            <div className="flex flex-wrap items-end gap-2">
              <input
                className="input w-40"
                type="number"
                min="1"
                max="3650"
                value={state.contactChangeRetentionDays}
                onChange={(event) =>
                  setState((prev) => ({
                    ...prev,
                    contactChangeRetentionDays: event.target.value,
                  }))
                }
              />
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={saveContactChangeRetention}
                disabled={retentionSubmitting}
              >
                {retentionSubmitting
                  ? t("controlPanel.queueRetentionSaving")
                  : t("controlPanel.queueRetentionSave")}
              </button>
            </div>
          </Field>
          <div className="mt-4">
            <Field label={t("controlPanel.milestoneGenerationHorizon")}>
              <p className="mb-2 text-xs text-app-faint">
                {t("controlPanel.milestoneGenerationHorizonDescription")}
              </p>
              <div className="flex flex-wrap items-end gap-2">
                <input
                  className="input w-40"
                  type="number"
                  min="1"
                  max="25"
                  value={state.milestoneGenerationYears}
                  onChange={(event) =>
                    setState((prev) => ({
                      ...prev,
                      milestoneGenerationYears: event.target.value,
                    }))
                  }
                />
                <button
                  className="btn-outline btn-outline-sm"
                  type="button"
                  onClick={saveMilestoneGenerationYears}
                  disabled={milestoneGenerationSubmitting}
                >
                  {milestoneGenerationSubmitting
                    ? t("controlPanel.milestoneGenerationHorizonSaving")
                    : t("controlPanel.milestoneGenerationHorizonSave")}
                </button>
              </div>
            </Field>
          </div>
        </div>
        <div className="mt-6 rounded-2xl border border-app-edge bg-app-surface p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 className="text-sm font-semibold text-app-strong">
                {t("backups.title")}
              </h3>
              <p className="mt-1 text-xs text-app-faint">
                {t("backups.description")}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <button
                className="mr-2 inline-flex items-center gap-1 px-1 text-xs font-medium text-app-muted transition hover:text-app-strong"
                type="button"
                onClick={openBackupRestoreDrawer}
              >
                {t("backups.restore.label")}
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="14"
                  height="14"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  <path d="M12 21V9" />
                  <path d="m7 14 5-5 5 5" />
                  <path d="M5 4h14" />
                </svg>
                {/* <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="12"
                  height="12"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="M1 4v6h6" />
                  <path d="M3.51 15a9 9 0 1 0 .49-7L1 10" />
                </svg>*/}
              </button>
              <button
                className={backupRunNowButtonClass}
                type="button"
                onClick={runBackupNow}
                disabled={backupRunNowDisabled}
                title={
                  !backupHasDestination
                    ? t("backups.configureFirst")
                    : undefined
                }
              >
                {backupRunning
                  ? t("backups.runningBackup")
                  : t("backups.runBackupNow")}
              </button>
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={openBackupConfigDrawer}
              >
                {t("backups.configure")}
              </button>
            </div>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1 rounded-full border border-app-edge bg-app-surface px-2.5 py-1 text-xs">
              <span className="text-app-faint">{t("backups.status")}</span>
              <span className="font-semibold text-app-strong">
                {state.backupEnabled
                  ? t("backups.enabled")
                  : t("backups.disabled")}
              </span>
            </span>
            <span className="inline-flex items-center gap-1 rounded-full border border-app-edge bg-app-surface px-2.5 py-1 text-xs">
              <span className="text-app-faint">
                {t("backups.destinations")}
              </span>
              <span className="font-semibold text-app-strong">
                {backupDestinationSummary || t("backups.noDestinations")}
              </span>
            </span>
            <span className="inline-flex items-center gap-1 rounded-full border border-app-edge bg-app-surface px-2.5 py-1 text-xs">
              <span className="text-app-faint">{t("backups.schedule")}</span>
              <span className="font-semibold text-app-strong">
                {backupScheduleSummary}
              </span>
            </span>
            <span className="inline-flex items-center gap-1 rounded-full border border-app-edge bg-app-surface px-2.5 py-1 text-xs">
              <span className="text-app-faint">{t("backups.retention")}</span>
              <span className="font-semibold text-app-strong">
                {backupRetentionSummary}
              </span>
            </span>
          </div>

          <p className="mt-3 text-xs text-app-faint">
            {t("backups.lastRun", { lastRunLabel: backupLastRunLabel })}
          </p>
          {state.backupLastRunMessage ? (
            <p
              className={`mt-1 text-xs ${
                state.backupLastRunStatus === "failed"
                  ? "text-app-danger"
                  : state.backupLastRunStatus === "success"
                    ? "text-app-accent"
                    : "text-app-faint"
              }`}
            >
              {state.backupLastRunMessage}
            </p>
          ) : null}
        </div>
        {state.milestonePurgeVisible ? (
          <div className="mt-6 flex flex-wrap items-center gap-2">
            <button
              className="btn-outline btn-outline-sm text-app-danger"
              type="button"
              onClick={purgeGeneratedMilestoneCalendars}
              disabled={
                milestonePurgeSubmitting || !state.milestonePurgeAvailable
              }
              title={
                !state.milestonePurgeAvailable
                  ? t("backups.purge.noEnabledCalendars")
                  : undefined
              }
            >
              {milestonePurgeSubmitting
                ? t("backups.purge.purging")
                : t("backups.purge.purgeGeneratedMilestoneCalendars")}
            </button>
            <p className="text-xs text-app-faint">
              {t(
                "backups.purge.deletesGeneratedBirthdayAnniversaryCalendars",
              )}
            </p>
          </div>
        ) : null}
        {milestonePurgeSummary ? (
          <p className="mt-2 text-sm text-app-accent">
            {milestonePurgeSummary}
          </p>
        ) : null}
        {state.error ? (
          <p className="mt-3 text-sm text-app-danger">{state.error}</p>
        ) : null}
      </div>

      {deleteUserTarget ? (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/45 p-4">
          <div
            className="w-full max-w-xl rounded-3xl border border-app-edge bg-app-surface p-6 shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-user-title"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3
                  id="delete-user-title"
                  className="text-lg font-semibold text-app-strong"
                >
                  {t("admin.deleteUserTitle", {
                    name: deleteUserTarget.name,
                  })}
                </h3>
                <p className="mt-1 text-sm text-app-muted">
                  {t("admin.deleteUserConfirmation")}
                </p>
              </div>
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={closeDeleteUserDialog}
                disabled={deleteUserSubmitting}
              >
                {t("admin.close")}
              </button>
            </div>

            <div className="mt-4 space-y-4">
              <label className="block">
                <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-app-faint">
                  {t("admin.transferOwnership")}
                </span>
                <select
                  className="input"
                  value={deleteUserTransferOwnerId}
                  onChange={(event) =>
                    setDeleteUserTransferOwnerId(event.target.value)
                  }
                  disabled={deleteUserSubmitting}
                >
                  <option value="">{t("admin.deleteAllOwnedData")}</option>
                  {deleteUserTransferOptions.map((candidate) => (
                    <option key={candidate.id} value={candidate.id}>
                      {candidate.name} ({candidate.email})
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-xs text-app-faint">
                  {t("admin.transferOwnershipDescription")}
                </p>
              </label>

              <label className="block">
                <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-app-faint">
                  {/* Type Your Admin Email To Confirm*/}
                  {t("admin.confirmEmail")}
                </span>
                <input
                  className="input"
                  type="email"
                  placeholder={adminConfirmationEmail || "admin@example.com"}
                  value={deleteUserConfirmationEmail}
                  onChange={(event) =>
                    setDeleteUserConfirmationEmail(event.target.value)
                  }
                  disabled={deleteUserSubmitting}
                />
              </label>
            </div>

            <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={closeDeleteUserDialog}
                disabled={deleteUserSubmitting}
              >
                {t("labels.cancel")}
              </button>
              <button
                className="btn-outline btn-outline-sm text-app-danger"
                type="button"
                onClick={deleteUser}
                disabled={deleteUserActionDisabled}
              >
                {deleteUserSubmitting
                  ? t("admin.deletingUser")
                  : t("admin.deleteUser")}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {backupConfigRendered ? (
        <div
          className={`fixed inset-0 z-40 ${
            backupConfigOpen ? "pointer-events-auto" : "pointer-events-none"
          }`}
          aria-hidden={!backupConfigOpen}
        >
          <button
            type="button"
            aria-label={t("backups.closeBackupConfig")}
            className={`absolute inset-0 bg-black/45 transition-opacity duration-200 ease-out motion-reduce:transition-none ${
              backupConfigOpen ? "opacity-100" : "opacity-0"
            }`}
            onClick={closeBackupConfigDrawer}
            tabIndex={backupConfigOpen ? 0 : -1}
          />
          <div
            className={`absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto border-l border-app-edge bg-app-surface p-5 shadow-2xl transition-all duration-200 ease-out motion-reduce:transition-none motion-reduce:transform-none ${
              backupConfigOpen
                ? "translate-x-0 opacity-100"
                : "translate-x-full opacity-0"
            }`}
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-xl font-semibold text-app-strong">
                  {t("backups.backupConfiguration")}
                </h3>
                <p className="mt-1 text-sm text-app-muted">
                  {t("backups.backupConfigurationDescription")}
                </p>
              </div>
              <button
                type="button"
                className="btn-outline btn-outline-sm"
                onClick={closeBackupConfigDrawer}
              >
                {t("labels.close")}
              </button>
            </div>

            <section className="mt-5 rounded-2xl border border-app-edge p-4">
              <div className="flex flex-wrap items-center gap-1.5">
                <AdminFeatureToggle
                  label={t("backups.enabled")}
                  enabled={state.backupEnabled}
                  onClick={() =>
                    setState((prev) => ({
                      ...prev,
                      backupEnabled: !prev.backupEnabled,
                    }))
                  }
                />
                <AdminFeatureToggle
                  label={t("backups.localDestination")}
                  enabled={state.backupLocalEnabled}
                  onClick={() =>
                    setState((prev) => ({
                      ...prev,
                      backupLocalEnabled: !prev.backupLocalEnabled,
                    }))
                  }
                />
                <AdminFeatureToggle
                  label={t("backups.s3Destination")}
                  enabled={state.backupS3Enabled}
                  onClick={() =>
                    setState((prev) => ({
                      ...prev,
                      backupS3Enabled: !prev.backupS3Enabled,
                    }))
                  }
                />
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                <Field label={t("backups.scheduleTimes")}>
                  <input
                    className="input"
                    value={state.backupScheduleTimes}
                    onChange={(event) =>
                      setState((prev) => ({
                        ...prev,
                        backupScheduleTimes: event.target.value,
                      }))
                    }
                    placeholder={t("backups.scheduleTimesPlaceholder")}
                  />
                </Field>
                <Field label={t("backups.timezone")}>
                  <select
                    className="input"
                    value={state.backupTimezone}
                    onChange={(event) =>
                      setState((prev) => ({
                        ...prev,
                        backupTimezone: event.target.value,
                      }))
                    }
                  >
                    {!backupTimezoneExistsInOptions && state.backupTimezone ? (
                      <option value={state.backupTimezone}>
                        {state.backupTimezone} (current)
                      </option>
                    ) : null}
                    {backupTimezoneGroups.map((group) => (
                      <optgroup key={group.region} label={group.region}>
                        {group.options.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </optgroup>
                    ))}
                  </select>
                </Field>
                {state.backupLocalEnabled ? (
                  <Field label={t("backups.localPath")}>
                    <input
                      className="input"
                      value={state.backupLocalPath}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupLocalPath: event.target.value,
                        }))
                      }
                      placeholder="/var/backups/davvy"
                    />
                  </Field>
                ) : null}
                {state.backupS3Enabled ? (
                  <Field label={t("backups.s3Disk")}>
                    <input
                      className="input"
                      value={state.backupS3Disk}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupS3Disk: event.target.value,
                        }))
                      }
                      placeholder="s3"
                    />
                  </Field>
                ) : null}
                {state.backupS3Enabled ? (
                  <Field label={t("backups.s3Prefix")}>
                    <input
                      className="input"
                      value={state.backupS3Prefix}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupS3Prefix: event.target.value,
                        }))
                      }
                      placeholder="davvy-backups"
                    />
                  </Field>
                ) : null}
              </div>
            </section>

            <section className="mt-4 rounded-2xl border border-app-edge p-4">
              <button
                className="flex w-full items-center justify-between text-left"
                type="button"
                onClick={() => setBackupAdvancedOpen((prev) => !prev)}
              >
                <span className="text-sm font-semibold text-app-strong">
                  {t("backups.advanced")}
                </span>
                <span className="text-xs text-app-muted">
                  {backupAdvancedOpen ? t("labels.hide") : t("labels.show")}
                </span>
              </button>

              {backupAdvancedOpen ? (
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  <Field label={t("backups.weeklyBackupDay")}>
                    <select
                      className="input"
                      value={state.backupWeeklyDay}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupWeeklyDay: Number(event.target.value),
                        }))
                      }
                    >
                      {WEEKDAY_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label={t("backups.monthlyBackupDay")}>
                    <input
                      className="input"
                      type="number"
                      min="1"
                      max="31"
                      value={state.backupMonthlyDay}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupMonthlyDay: event.target.value,
                        }))
                      }
                    />
                  </Field>
                  <Field label={t("backups.yearlyBackupMonth")}>
                    <select
                      className="input"
                      value={state.backupYearlyMonth}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupYearlyMonth: Number(event.target.value),
                        }))
                      }
                    >
                      {MONTH_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label={t("backups.yearlyBackupDay")}>
                    <input
                      className="input"
                      type="number"
                      min="1"
                      max="31"
                      value={state.backupYearlyDay}
                      onChange={(event) =>
                        setState((prev) => ({
                          ...prev,
                          backupYearlyDay: event.target.value,
                        }))
                      }
                    />
                  </Field>

                  <Field label={t("backups.retentionStrategy")}>
                    <select
                      className="input"
                      value={backupRetentionPreset}
                      onChange={(event) => {
                        const preset = event.target.value;
                        setBackupRetentionPreset(preset);

                        if (preset === "recommended") {
                          setState((prev) => ({
                            ...prev,
                            backupRetentionDaily:
                              RECOMMENDED_BACKUP_RETENTION.daily,
                            backupRetentionWeekly:
                              RECOMMENDED_BACKUP_RETENTION.weekly,
                            backupRetentionMonthly:
                              RECOMMENDED_BACKUP_RETENTION.monthly,
                            backupRetentionYearly:
                              RECOMMENDED_BACKUP_RETENTION.yearly,
                          }));
                        }
                      }}
                    >
                      <option value="recommended">
                        {t("backups.recommendedRetention")}
                      </option>
                      <option value="custom">
                        {t("backups.customRetention")}
                      </option>
                    </select>
                  </Field>

                  {backupRetentionPreset === "custom" ? (
                    <div className="md:col-span-2">
                      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label={t("backups.dailyRetention")}>
                          <input
                            className="input"
                            type="number"
                            min="0"
                            max="3650"
                            value={state.backupRetentionDaily}
                            onChange={(event) =>
                              setState((prev) => ({
                                ...prev,
                                backupRetentionDaily: event.target.value,
                              }))
                            }
                          />
                        </Field>
                        <Field label={t("backups.weeklyRetention")}>
                          <input
                            className="input"
                            type="number"
                            min="0"
                            max="520"
                            value={state.backupRetentionWeekly}
                            onChange={(event) =>
                              setState((prev) => ({
                                ...prev,
                                backupRetentionWeekly: event.target.value,
                              }))
                            }
                          />
                        </Field>
                        <Field label={t("backups.monthlyRetention")}>
                          <input
                            className="input"
                            type="number"
                            min="0"
                            max="240"
                            value={state.backupRetentionMonthly}
                            onChange={(event) =>
                              setState((prev) => ({
                                ...prev,
                                backupRetentionMonthly: event.target.value,
                              }))
                            }
                          />
                        </Field>
                        <Field label={t("backups.yearlyRetention")}>
                          <input
                            className="input"
                            type="number"
                            min="0"
                            max="50"
                            value={state.backupRetentionYearly}
                            onChange={(event) =>
                              setState((prev) => ({
                                ...prev,
                                backupRetentionYearly: event.target.value,
                              }))
                            }
                          />
                        </Field>
                      </div>
                    </div>
                  ) : null}
                </div>
              ) : null}
            </section>

            <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={closeBackupConfigDrawer}
              >
                {t("labels.cancel")}
              </button>
              <button
                className={backupSaveButtonClass}
                type="button"
                onClick={saveBackupSettings}
                disabled={backupSaving}
              >
                {backupSaving
                  ? t("backups.savingSettings")
                  : t("backups.saveSettings")}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {backupRestoreRendered ? (
        <div
          className={`fixed inset-0 z-40 ${
            backupRestoreOpen ? "pointer-events-auto" : "pointer-events-none"
          }`}
          aria-hidden={!backupRestoreOpen}
        >
          <button
            type="button"
            aria-label={t("backups.closeRestore")}
            className={`absolute inset-0 bg-black/45 transition-opacity duration-200 ease-out motion-reduce:transition-none ${
              backupRestoreOpen ? "opacity-100" : "opacity-0"
            }`}
            onClick={closeBackupRestoreDrawer}
            tabIndex={backupRestoreOpen ? 0 : -1}
          />
          <div
            className={`absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto border-l border-app-edge bg-app-surface p-5 shadow-2xl transition-all duration-200 ease-out motion-reduce:transition-none motion-reduce:transform-none ${
              backupRestoreOpen
                ? "translate-x-0 opacity-100"
                : "translate-x-full opacity-0"
            }`}
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-xl font-semibold text-app-strong">
                  {t("backups.restoreArchive")}
                </h3>
                <p className="mt-1 text-sm text-app-muted">
                  {t("backups.restoreArchiveDescription")}
                </p>
              </div>
              <button
                type="button"
                className="btn-outline btn-outline-sm"
                onClick={closeBackupRestoreDrawer}
              >
                {t("labels.close")}
              </button>
            </div>

            <section className="mt-5 rounded-2xl border border-app-edge p-4">
              <div className="grid gap-3 md:grid-cols-2">
                <Field label={t("backups.backupZipFile")}>
                  <input
                    className="input"
                    type="file"
                    accept=".zip,application/zip"
                    onChange={(event) => {
                      const nextFile = event.target.files?.[0] ?? null;
                      setBackupRestoreFile(nextFile);
                    }}
                  />
                </Field>
                <Field label={t("backups.restore.mode")}>
                  <select
                    className="input"
                    value={backupRestoreMode}
                    onChange={(event) =>
                      setBackupRestoreMode(event.target.value)
                    }
                  >
                    <option value="merge">{t("backups.restore.merge")}</option>
                    <option value="replace">
                      {t("backups.restore.replace")}
                    </option>
                  </select>
                </Field>
              </div>

              {backupRestoreFile ? (
                <p className="mt-2 max-w-full truncate text-xs text-app-faint">
                  {t("backups.restore.selectedFile", {
                    name: backupRestoreFile.name,
                  })}
                </p>
              ) : null}

              <label className="mt-3 inline-flex items-center gap-2 text-xs text-app-faint">
                <input
                  type="checkbox"
                  checked={backupRestoreDryRun}
                  onChange={(event) =>
                    setBackupRestoreDryRun(!!event.target.checked)
                  }
                />
                {t("backups.restore.dryRunDescription")}
              </label>

              {backupRestoreMode === "replace" ? (
                <p className="mt-2 text-xs text-app-danger">
                  {t("backups.restore.replaceWarning")}
                </p>
              ) : null}
            </section>

            {backupRestoreResult ? (
              <div className="mt-4 rounded-xl border border-app-edge bg-app-surface p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-app-faint">
                  {t("backups.restore.result")}
                </p>
                <p className="mt-1 text-sm text-app-strong">
                  {backupRestoreResult.reason || t("backups.restore.success")}
                </p>

                {backupRestoreSummary ? (
                  <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.filesProcessed")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(backupRestoreSummary.files_processed || 0)}
                      </span>
                    </p>
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.filesSkipped")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(backupRestoreSummary.files_skipped || 0)}
                      </span>
                    </p>
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.calendars")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(backupRestoreSummary.calendars_created || 0)}/
                        {Number(backupRestoreSummary.calendars_updated || 0)}
                      </span>
                    </p>
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.addressBooks")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(
                          backupRestoreSummary.address_books_created || 0,
                        )}
                        /
                        {Number(
                          backupRestoreSummary.address_books_updated || 0,
                        )}
                      </span>
                    </p>
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.objects")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(
                          (backupRestoreSummary.calendar_objects_created || 0) +
                            (backupRestoreSummary.cards_created || 0),
                        )}
                        /
                        {Number(
                          (backupRestoreSummary.calendar_objects_updated || 0) +
                            (backupRestoreSummary.cards_updated || 0),
                        )}
                      </span>
                    </p>
                    <p className="text-xs text-app-faint">
                      {t("backups.restore.invalidResources")}:{" "}
                      <span className="font-semibold text-app-strong">
                        {Number(
                          backupRestoreSummary.resources_skipped_invalid || 0,
                        )}
                      </span>
                    </p>
                  </div>
                ) : null}

                {backupRestoreWarnings.length > 0 ? (
                  <div className="mt-3">
                    <p className="text-xs font-semibold text-app-faint">
                      {t("backups.restore.warnings")}
                    </p>
                    <ul className="mt-1 list-disc space-y-1 pl-4 text-xs text-app-faint">
                      {backupRestoreWarnings.map((warning, index) => (
                        <li key={`${warning}-${index}`}>{warning}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            ) : null}

            <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                onClick={closeBackupRestoreDrawer}
              >
                {t("labels.cancel")}
              </button>
              <button
                className={backupRestoreRunButtonClass}
                type="button"
                onClick={runBackupRestore}
                disabled={backupRestoreRunDisabled}
              >
                {backupRestoring
                  ? t("backups.restore.running")
                  : backupRestoreDryRun
                    ? t("backups.restore.dryRun")
                    : t("backups.restore.run")}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {state.loading ? (
        <FullPageState label={t("states.loadingAdminData")} compact />
      ) : (
        <div className="mt-6 grid gap-6 xl:grid-cols-2">
          <section className="surface rounded-3xl p-6">
            <h3 className="text-lg font-semibold">{t("admin.createUser")}</h3>
            <form className="mt-3 space-y-3" onSubmit={createUser}>
              <input
                className="input"
                placeholder={t("admin.namePlaceholder")}
                value={userForm.name}
                onChange={(e) =>
                  setUserForm({ ...userForm, name: e.target.value })
                }
                required
              />
              <input
                className="input"
                type="email"
                placeholder={t("admin.emailPlaceholder")}
                value={userForm.email}
                onChange={(e) =>
                  setUserForm({ ...userForm, email: e.target.value })
                }
                required
              />
              <select
                className="input"
                value={userForm.role}
                onChange={(e) =>
                  setUserForm({ ...userForm, role: e.target.value })
                }
              >
                <option value="regular">{t("admin.role.regular")}</option>
                <option value="admin">{t("admin.role.admin")}</option>
              </select>
              <button className="btn" type="submit">
                {t("admin.createUser")}
              </button>
            </form>
            {userInviteResult ? (
              <div className="mt-3 rounded-xl border border-app-edge bg-app-panel p-3 text-xs text-app-muted">
                <p className="font-semibold text-app-strong">
                  {userInviteResult.message}
                </p>
                {userInviteResult.invitationUrl ? (
                  <p className="mt-1 break-all">
                    {userInviteResult.invitationUrl}
                  </p>
                ) : null}
              </div>
            ) : null}

            <div className="mt-5 space-y-2">
              {state.users.map((user) => {
                const isApproved = user.is_approved !== false;

                return (
                  <div
                    key={user.id}
                    className="rounded-xl border border-app-edge bg-app-surface p-3 text-sm"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <p className="font-semibold text-app-strong">
                        {user.name}
                      </p>
                      <div className="flex flex-wrap items-center gap-1">
                        {!isApproved ? (
                          <button
                            className="btn-outline btn-outline-sm inline-flex items-center gap-1"
                            type="button"
                            onClick={() => approveUser(user.id)}
                          >
                            <span>{t("admin.approveUser")}</span>
                            {CheckIcon ? (
                              <CheckIcon className="h-3.5 w-3.5" />
                            ) : null}
                          </button>
                        ) : null}
                        {Number(user.id) !== Number(auth.user?.id) ? (
                          <button
                            className="btn-outline btn-outline-sm text-app-danger"
                            type="button"
                            onClick={() => openDeleteUserDialog(user)}
                          >
                            {t("labels.delete")}
                          </button>
                        ) : null}
                      </div>
                    </div>
                    <p className="text-app-muted">{user.email}</p>
                    <p className="text-xs text-app-faint">
                      {t("admin.userDetails", {
                        role: user.role,
                        calendars: user.calendars_count,
                        address_books: user.address_books_count,
                        two_factor: user.two_factor_enabled
                          ? t("labels.enabled")
                          : t("labels.disabled"),
                      })}
                    </p>
                    <button
                      className="mt-2 text-xs font-semibold text-app-danger"
                      type="button"
                      onClick={() => resetUserTwoFactor(user.id)}
                    >
                      {t("admin.reset2fa")}
                    </button>
                    {!isApproved ? (
                      <p className="text-xs text-app-faint">
                        {t("admin.statusPendingApproval")}
                      </p>
                    ) : null}
                  </div>
                );
              })}
            </div>
          </section>

          <section className="surface rounded-3xl p-6">
            <h3 className="text-lg font-semibold">
              {t("admin.assignShareAccess")}
            </h3>
            <form className="mt-3 space-y-3" onSubmit={saveShare}>
              <select
                className="input"
                value={shareForm.resource_type}
                onChange={(e) =>
                  setShareForm({
                    ...shareForm,
                    resource_type: e.target.value,
                    resource_id: "",
                  })
                }
              >
                <option value="calendar">{t("labels.calendar")}</option>
                <option value="address_book">{t("labels.addressBook")}</option>
              </select>
              <select
                className="input"
                value={shareForm.resource_id}
                onChange={(e) =>
                  setShareForm({ ...shareForm, resource_id: e.target.value })
                }
                required
              >
                <option value="">
                  {t("labels.share.selectSharableResource")}
                </option>
                {resourceOptions.map((resource) => (
                  <option key={resource.id} value={resource.id}>
                    {resource.display_name} ({resource.owner?.email})
                  </option>
                ))}
              </select>
              <select
                className="input"
                value={shareForm.shared_with_id}
                onChange={(e) =>
                  setShareForm({ ...shareForm, shared_with_id: e.target.value })
                }
                required
              >
                <option value="">{t("labels.share.selectUser")}</option>
                {state.users.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name} ({user.email})
                  </option>
                ))}
              </select>
              <select
                className="input"
                value={shareForm.permission}
                onChange={(e) =>
                  setShareForm({ ...shareForm, permission: e.target.value })
                }
              >
                <option value="read_only">
                  {t("labels.share.permission.general")}
                </option>
                <option value="editor">
                  {t("labels.share.permission.editor")}
                </option>
                <option value="admin">
                  {t("labels.share.permission.admin")}
                </option>
              </select>
              <button className="btn" type="submit">
                {t("labels.share.save")}
              </button>
            </form>

            <div className="mt-5 space-y-2">
              {state.shares.map((share) => (
                <div
                  key={share.id}
                  className="rounded-xl border border-app-edge bg-app-surface p-3 text-sm"
                >
                  <div className="flex items-center justify-between">
                    <p className="font-semibold text-app-strong">
                      {share.resource_type} #{share.resource_id}
                    </p>
                    <PermissionBadge permission={share.permission} />
                  </div>
                  <p className="text-app-muted">
                    {t("labels.share.owner", {
                      name: share.owner.name,
                      email: share.owner.email,
                    })}
                  </p>
                  <p className="text-app-muted">
                    {t("labels.share.sharedWith", {
                      name: share.shared_with.name,
                      email: share.shared_with.email,
                    })}
                  </p>
                  <button
                    className="mt-2 text-xs font-semibold text-app-danger"
                    onClick={() => deleteShare(share.id)}
                  >
                    {t("labels.remove")}
                  </button>
                </div>
              ))}
            </div>
          </section>
        </div>
      )}
    </AppShell>
  );
}
