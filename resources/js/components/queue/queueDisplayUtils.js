import i18n from "../../i18n";
import { normalizeLocale } from "../../lib/locale";

function activeLocale() {
  return normalizeLocale(i18n.resolvedLanguage || i18n.language || "en");
}

/**
 * Formats a queue timestamp for local display.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function formatQueueTimestamp(value) {
  if (!value) {
    return i18n.t("labels.na", {
      ns: "common",
      defaultValue: "n/a",
    });
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return i18n.t("labels.na", {
      ns: "common",
      defaultValue: "n/a",
    });
  }

  return parsed.toLocaleString(activeLocale());
}

/**
 * Maps a queue status code to its user-facing label.
 *
 * @param {string|null|undefined} status
 * @returns {string}
 */
export function queueStatusLabel(status) {
  switch (status) {
    case "pending":
      return i18n.t("status.pending", { ns: "queue", defaultValue: "Pending" });
    case "approved":
      return i18n.t("status.approved", {
        ns: "queue",
        defaultValue: "Approved (awaiting others)",
      });
    case "manual_merge_needed":
      return i18n.t("status.manualMergeNeeded", {
        ns: "queue",
        defaultValue: "Manual Merge Needed",
      });
    case "applied":
      return i18n.t("status.applied", { ns: "queue", defaultValue: "Applied" });
    case "denied":
      return i18n.t("status.denied", { ns: "queue", defaultValue: "Denied" });
    default:
      return (
        status ||
        i18n.t("labels.unknown", { ns: "common", defaultValue: "Unknown" })
      );
  }
}

/**
 * Maps queue operation type to a user-facing label.
 *
 * @param {string|null|undefined} operation
 * @returns {string}
 */
export function queueOperationLabel(operation) {
  return operation === "delete"
    ? i18n.t("operations.delete", { ns: "queue", defaultValue: "Delete" })
    : i18n.t("operations.update", { ns: "queue", defaultValue: "Update" });
}
