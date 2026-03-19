import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

/**
 * Renders the Info Card component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function InfoCard({
  title,
  value,
  helper,
  copyable = false,
  copyTextToClipboard,
}) {
  const { t } = useTranslation("common");
  const [copyState, setCopyState] = useState("idle");

  useEffect(() => {
    if (copyState === "idle") {
      return undefined;
    }

    const timer = window.setTimeout(() => setCopyState("idle"), 1800);
    return () => window.clearTimeout(timer);
  }, [copyState]);

  const copyValue = async () => {
    if (!copyable) {
      return;
    }

    try {
      await copyTextToClipboard(value);
      setCopyState("copied");
    } catch {
      setCopyState("failed");
    }
  };

  const copyTooltipLabel =
    copyState === "copied"
      ? t("copy.copied")
      : copyState === "failed"
        ? t("copy.copyFailed")
        : "";
  const copyTooltipTone = copyState === "failed" ? "bg-red-700" : "bg-teal-700";

  return (
    <article className="surface rounded-2xl p-4">
      <p className="text-xs font-semibold uppercase tracking-wide text-app-faint">
        {title}
      </p>
      {copyable ? (
        <div className="relative mt-1">
          <button
            type="button"
            onClick={() => void copyValue()}
            className="w-full rounded-md text-left text-base font-bold text-app-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
            aria-label={t("copy.ariaCopy", { value: title })}
            title={t("copy.clickToCopy")}
          >
            <span className="break-all">{value}</span>
          </button>
          <span
            className={`pointer-events-none absolute right-0 top-0 rounded-md px-2 py-1 text-[11px] font-semibold text-white transition-opacity duration-150 ${
              copyState === "idle" ? "opacity-0" : "opacity-100"
            } ${copyTooltipTone}`}
          >
            {copyTooltipLabel}
          </span>
        </div>
      ) : (
        <p className="mt-1 break-all text-base font-bold text-app-strong">
          {value}
        </p>
      )}
      <p className="mt-2 text-xs text-app-muted">{helper}</p>
    </article>
  );
}
