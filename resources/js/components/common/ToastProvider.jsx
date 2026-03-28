import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import Toast from "./Toast";

const DEFAULT_AUTO_HIDE_MS = 3200;
const defaultContextValue = {
  showToast: () => {},
  clearToast: () => {},
};

const ToastContext = createContext(defaultContextValue);

/**
 * Provides a shared app-level toast surface.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export function ToastProvider({ children, autoHideMs = DEFAULT_AUTO_HIDE_MS }) {
  const [toast, setToast] = useState(null);

  const showToast = useCallback(
    ({ status = "status", message = "", durationMs } = {}) => {
      const normalizedMessage = String(message || "").trim();
      if (normalizedMessage === "") {
        return;
      }

      setToast({
        id: Date.now(),
        status,
        message: normalizedMessage,
        durationMs,
      });
    },
    [],
  );

  const clearToast = useCallback(() => {
    setToast(null);
  }, []);

  useEffect(() => {
    if (!toast) {
      return undefined;
    }

    const nextDuration = Number(toast.durationMs);
    const shouldUseCustomDuration =
      Number.isFinite(nextDuration) && nextDuration >= 0;
    const delayMs = shouldUseCustomDuration ? nextDuration : autoHideMs;

    if (delayMs <= 0) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      setToast((current) =>
        current && current.id === toast.id ? null : current,
      );
    }, delayMs);

    return () => window.clearTimeout(timer);
  }, [toast, autoHideMs]);

  const contextValue = useMemo(
    () => ({
      showToast,
      clearToast,
    }),
    [showToast, clearToast],
  );

  return (
    <ToastContext.Provider value={contextValue}>
      {children}
      {toast ? <Toast status={toast.status} message={toast.message} /> : null}
    </ToastContext.Provider>
  );
}

export function useToast() {
  return useContext(ToastContext);
}

