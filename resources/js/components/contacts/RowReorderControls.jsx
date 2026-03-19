import React from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Row Reorder Controls component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function RowReorderControls({
  rowLabel,
  rowGroup,
  rowIndex,
  rowCount,
  onDragStart,
  onDragMove,
  onDragEnd,
  onDragCancel,
  onMoveUp,
  onMoveDown,
  onRemove,
  showHandle = true,
  showActions = true,
}) {
  const { t } = useTranslation("contacts");
  const canMoveUp = rowIndex > 0;
  const canMoveDown = rowIndex < rowCount - 1;
  const rowNumber = rowIndex + 1;
  const iconControlClass =
    "btn-outline btn-outline-sm !h-8 !w-8 !px-0 !py-0 hidden group-hover/row:inline-flex group-focus-within/row:inline-flex";

  return (
    <div className="flex items-center justify-end gap-1.5">
      {showActions ? (
        <>
          <button
            className={iconControlClass}
            type="button"
            onClick={() => onMoveUp(rowIndex)}
            disabled={!canMoveUp}
            aria-label={t("editor.row_reorder_controls.move_up_aria", {
              rowLabel,
              rowNumber,
            })}
            title={t("editor.row_reorder_controls.move_up")}
          >
            <svg
              className="h-3.5 w-3.5"
              viewBox="0 0 12 12"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M2.5 7.5L6 4l3.5 3.5" />
            </svg>
          </button>
          <button
            className={iconControlClass}
            type="button"
            onClick={() => onMoveDown(rowIndex)}
            disabled={!canMoveDown}
            aria-label={t("editor.row_reorder_controls.move_down_aria", {
              rowLabel,
              rowNumber,
            })}
            title={t("editor.row_reorder_controls.move_down")}
          >
            <svg
              className="h-3.5 w-3.5"
              viewBox="0 0 12 12"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.7"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M2.5 4.5L6 8l3.5-3.5" />
            </svg>
          </button>
          <button
            className="btn-outline btn-outline-sm"
            type="button"
            onClick={() => onRemove(rowIndex)}
            aria-label={t("editor.row_reorder_controls.remove_aria", {
              rowLabel,
              rowNumber,
            })}
          >
            {t("editor.row_reorder_controls.remove")}
          </button>
        </>
      ) : null}
      {showHandle ? (
        <button
          className="inline-flex h-7 w-7 cursor-grab touch-none items-center justify-center rounded-lg bg-transparent text-app-faint transition hover:text-app-accent active:cursor-grabbing focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-300"
          type="button"
          aria-label={t("editor.row_reorder_controls.drag_aria", {
            rowLabel,
            rowNumber,
          })}
          title={t("editor.row_reorder_controls.drag_title")}
          data-reorder-group={rowGroup}
          onPointerDown={(event) => onDragStart(rowIndex, event)}
          onPointerMove={onDragMove}
          onPointerUp={(event) => onDragEnd(event, false)}
          onPointerCancel={(event) => onDragCancel(event, true)}
        >
          <svg
            className="h-4 w-4"
            viewBox="0 0 12 12"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M3 3.25h6" />
            <path d="M3 6h6" />
            <path d="M3 8.75h6" />
          </svg>
        </button>
      ) : null}
    </div>
  );
}
