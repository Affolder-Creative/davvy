export function isServiceWorkerSupported() {
  return (
    typeof window !== "undefined" &&
    typeof navigator !== "undefined" &&
    "serviceWorker" in navigator
  );
}

export async function registerDavvyServiceWorker({
  onUpdateAvailable,
  onOfflineReady,
} = {}) {
  if (!isServiceWorkerSupported()) {
    return null;
  }

  const registration = await navigator.serviceWorker.register("/sw.js");

  if (registration.waiting) {
    onUpdateAvailable?.(registration);
  }

  registration.addEventListener?.("updatefound", () => {
    const installingWorker = registration.installing;

    if (!installingWorker) {
      return;
    }

    installingWorker.addEventListener("statechange", () => {
      if (installingWorker.state !== "installed") {
        return;
      }

      if (navigator.serviceWorker.controller) {
        onUpdateAvailable?.(registration);
      } else {
        onOfflineReady?.(registration);
      }
    });
  });

  return registration;
}

export function activateWaitingServiceWorker(registration) {
  if (!registration?.waiting) {
    return false;
  }

  registration.waiting.postMessage({ type: "SKIP_WAITING" });

  return true;
}
