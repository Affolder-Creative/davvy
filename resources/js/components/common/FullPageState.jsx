import React from "react";

/**
 * Renders the Full Page State component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function FullPageState({ label, compact = false }) {
  if (!compact) {
    return (
      <div className="app-loading-screen" role="status" aria-live="polite">
        <div className="app-loading-brand">
          <img
            className="app-loading-icon app-loading-icon-light"
            src="/davvy.png"
            alt=""
            width="136"
            height="136"
          />
          <img
            className="app-loading-icon app-loading-icon-dark"
            src="/davvy_dark.png"
            alt=""
            width="136"
            height="136"
          />
          <p className="app-loading-title">Davvy</p>
          <p className="app-loading-label">{label}</p>
        </div>
      </div>
    );
  }

  return (
    <div
      className="mt-4 text-sm font-semibold text-app-muted"
    >
      {label}
    </div>
  );
}
