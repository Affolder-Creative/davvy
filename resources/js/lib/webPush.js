export function isWebPushSupported() {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window
  );
}

export function notificationPermission() {
  if (typeof window === "undefined" || !("Notification" in window)) {
    return "unsupported";
  }

  return Notification.permission;
}

export async function registerDavvyServiceWorker() {
  if (!isWebPushSupported()) {
    return null;
  }

  return navigator.serviceWorker.register("/sw.js");
}

export async function currentPushSubscription() {
  const registration = await registerDavvyServiceWorker();
  if (!registration) {
    return null;
  }

  return registration.pushManager.getSubscription();
}

export async function subscribeToWebPush(publicKey) {
  if (!isWebPushSupported()) {
    throw new Error("WebPush is not supported in this browser.");
  }

  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    throw new Error("Notification permission was not granted.");
  }

  const registration = await registerDavvyServiceWorker();
  if (!registration) {
    throw new Error("Service worker registration failed.");
  }

  return registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  });
}

export async function unsubscribeFromWebPush() {
  const subscription = await currentPushSubscription();
  if (!subscription) {
    return null;
  }

  await subscription.unsubscribe();
  return subscription;
}

export function serializePushSubscription(subscription) {
  if (!subscription || typeof subscription.toJSON !== "function") {
    return null;
  }

  const json = subscription.toJSON();

  return {
    endpoint: json.endpoint,
    keys: {
      p256dh: json.keys?.p256dh,
      auth: json.keys?.auth,
    },
    content_encoding: "aes128gcm",
  };
}

export async function setDavvyAppBadge(count) {
  try {
    const normalizedCount = Number(count || 0);
    if (normalizedCount > 0 && navigator.setAppBadge) {
      await navigator.setAppBadge(normalizedCount);
    } else if (navigator.clearAppBadge) {
      await navigator.clearAppBadge();
    }
  } catch {
    // Browser support is intentionally best-effort.
  }
}

export function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = `${base64String}${padding}`
    .replace(/-/g, "+")
    .replace(/_/g, "/");
  const rawData = window.atob(base64);
  const output = new Uint8Array(rawData.length);

  for (let index = 0; index < rawData.length; index += 1) {
    output[index] = rawData.charCodeAt(index);
  }

  return output;
}
