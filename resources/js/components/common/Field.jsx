import React from "react";

/**
 * Renders the Field component.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function Field({ label, required = false, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-semibold text-app-base">
        {label}
        {required ? (
          <span className="ml-1 text-app-danger" aria-hidden="true">
            *
          </span>
        ) : null}
      </span>
      {children}
    </label>
  );
}
