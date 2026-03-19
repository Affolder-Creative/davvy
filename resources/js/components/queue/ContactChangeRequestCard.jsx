import React from "react";
import { useTranslation } from "react-i18next";
import {
  formatQueueTimestamp,
  queueOperationLabel,
  queueStatusLabel,
} from "./queueDisplayUtils";

/**
 * Renders the Contact Change Request Card component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function ContactChangeRequestCard({
  row,
  submitting,
  onOpenEdit,
  onApprove,
  onDeny,
}) {
  const { t } = useTranslation("queue");
  const isActionable =
    row.status === "pending" || row.status === "manual_merge_needed";

  return (
    <article
      className={`surface rounded-2xl p-4 ${
        row.status === "manual_merge_needed"
          ? "border border-app-warn-edge bg-app-warn-surface"
          : ""
      }`}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-app-faint">
            #{row.id} • {t("card.group")} {row.group_uuid}
          </p>
          <h3 className="text-lg font-semibold text-app-strong">
            {row.contact?.display_name || t("card.unnamed_contact")} (
            {queueOperationLabel(row.operation)})
          </h3>
          <p className="mt-1 text-xs text-app-muted">
            {t("card.status")}: {queueStatusLabel(row.status)} • {t("card.requested")}{" "}
            {formatQueueTimestamp(row.created_at)}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {isActionable ? (
            <>
              {row.operation === "update" ? (
                <button
                  className="btn-outline btn-outline-sm"
                  type="button"
                  disabled={submitting}
                  onClick={() => onOpenEdit(row)}
                >
                  {t("card.edit_approve")}
                </button>
              ) : null}
              <button
                className="btn-outline btn-outline-sm"
                type="button"
                disabled={submitting}
                onClick={() => onApprove(row)}
              >
                {t("card.approve")}
              </button>
              <button
                className="btn-outline btn-outline-sm text-app-danger"
                type="button"
                disabled={submitting}
                onClick={() => onDeny(row)}
              >
                {t("card.deny")}
              </button>
            </>
          ) : null}
        </div>
      </div>

      <div className="mt-3 grid gap-2 text-sm text-app-base md:grid-cols-2">
        <p>
          {t("card.requester")}: {row.requester?.name} ({row.requester?.email})
        </p>
        <p>
          {t("card.approval_owner")}: {row.approval_owner?.name} ({row.approval_owner?.email})
        </p>
        <p>{t("card.source")}: {row.source}</p>
        <p>
          {t("card.reviewer")}:{" "}
          {row.reviewer
            ? `${row.reviewer.name} (${row.reviewer.email})`
            : t("card.not_reviewed")}
        </p>
      </div>

      {Array.isArray(row.changed_fields) && row.changed_fields.length > 0 ? (
        <p className="mt-2 text-xs text-app-muted">
          {t("card.changed_fields")}: {row.changed_fields.join(", ")}
        </p>
      ) : null}

      {row.status_reason ? (
        <p className="mt-2 text-sm text-app-danger">{row.status_reason}</p>
      ) : null}
    </article>
  );
}
