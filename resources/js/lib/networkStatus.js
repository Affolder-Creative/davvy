import { useEffect, useState } from "react";

export function isNavigatorOnline() {
  if (typeof navigator === "undefined") {
    return true;
  }

  return navigator.onLine !== false;
}

export function useNetworkStatus() {
  const [isOnline, setIsOnline] = useState(isNavigatorOnline);

  useEffect(() => {
    if (typeof window === "undefined") {
      return undefined;
    }

    const updateStatus = () => setIsOnline(isNavigatorOnline());

    window.addEventListener("online", updateStatus);
    window.addEventListener("offline", updateStatus);
    updateStatus();

    return () => {
      window.removeEventListener("online", updateStatus);
      window.removeEventListener("offline", updateStatus);
    };
  }, []);

  return { isOnline };
}
