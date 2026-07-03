self.addEventListener("install", (event) => {
  event.waitUntil(precacheAppShell());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    Promise.all([
      cleanupCaches(),
      self.clients && "claim" in self.clients ? self.clients.claim() : null,
    ]),
  );
});

self.addEventListener("fetch", (event) => {
  if (shouldBypassRequest(event.request)) {
    return;
  }

  if (isNavigationRequest(event.request)) {
    event.respondWith(handleNavigationRequest(event.request));
    return;
  }

  if (isStaticAssetRequest(event.request)) {
    event.respondWith(handleStaticAssetRequest(event.request));
  }
});

self.addEventListener("message", (event) => {
  if (event.data?.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

self.addEventListener("push", (event) => {
  event.waitUntil(handlePush(event));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  event.waitUntil(openNotificationTarget(event.notification.data));
});

async function handlePush(event) {
  const payload = parsePushPayload(event);
  const notification = normalizeNotificationPayload(payload);

  await updateBadge(notification.badgeCount);
  await self.registration.showNotification(
    notification.title,
    notification.options,
  );
}

function parsePushPayload(event) {
  if (!event.data) {
    return {};
  }

  try {
    const parsed = event.data.json();
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function normalizeNotificationPayload(payload) {
  const source =
    payload.notification && typeof payload.notification === "object"
      ? payload.notification
      : {};
  const sourceData =
    source.data && typeof source.data === "object" ? source.data : {};
  const badgeCount = normalizeBadgeCount(source.app_badge);
  const targetUrl = normalizeTargetUrl(source.navigate);

  return {
    title: source.title || "Davvy",
    badgeCount,
    options: {
      body: source.body || "",
      icon: source.icon || "/images/icons/icon-192.png",
      badge: source.badge || "/images/icons/icon-192.png",
      tag: source.tag || sourceData.type || "davvy-notification",
      data: {
        ...sourceData,
        url: targetUrl,
        badge_count: badgeCount,
      },
    },
  };
}

function normalizeBadgeCount(value) {
  const count = Number(value ?? 0);

  return Number.isFinite(count) && count > 0 ? count : 0;
}

async function updateBadge(count) {
  if (!self.navigator) {
    return;
  }

  try {
    if (Number.isFinite(count) && count > 0 && self.navigator.setAppBadge) {
      await self.navigator.setAppBadge(count);
    } else if (self.navigator.clearAppBadge) {
      await self.navigator.clearAppBadge();
    }
  } catch {
    // Badge support varies by browser; notification display should continue.
  }
}

async function openNotificationTarget(data) {
  const targetUrl = new URL(normalizeTargetUrl(data?.url), self.location.origin);
  const windows = await self.clients.matchAll({
    type: "window",
    includeUncontrolled: true,
  });

  for (const client of windows) {
    const clientUrl = new URL(client.url);
    if (clientUrl.origin === targetUrl.origin) {
      await client.focus();
      if ("navigate" in client) {
        return client.navigate(targetUrl.href);
      }

      return;
    }
  }

  await self.clients.openWindow(targetUrl.href);
}

function normalizeTargetUrl(url) {
  if (typeof url !== "string" || url.trim() === "") {
    return "/";
  }

  try {
    const parsed = new URL(url, self.location.origin);
    return parsed.origin === self.location.origin ? parsed.pathname + parsed.search + parsed.hash : "/";
  } catch {
    return "/";
  }
}

const SHELL_CACHE = "davvy-shell-v1";
const CACHE_PREFIX = "davvy-";
const APP_SHELL_URLS = [
  "/",
  "/manifest.webmanifest",
  "/favicon.svg",
  "/favicon.ico",
  "/images/icons/icon-192.png",
  "/images/icons/icon-384.png",
  "/images/icons/icon-512.png",
  "/images/icons/icon-512-maskable.png",
  "/images/icons/icon-1024.png",
  "/images/icons/apple-touch-icon-180.png",
];

async function precacheAppShell() {
  const cache = await caches.open(SHELL_CACHE);
  await cacheStaticUrls(cache, APP_SHELL_URLS);
  await cacheBuildAssets(cache);
}

async function cacheBuildAssets(cache) {
  let response;

  try {
    response = await fetch("/build/manifest.json", { cache: "no-cache" });
  } catch {
    return;
  }

  if (!response.ok) {
    return;
  }

  await cache.put("/build/manifest.json", response.clone());

  let manifest;

  try {
    manifest = await response.json();
  } catch {
    return;
  }

  await cacheStaticUrls(cache, buildAssetUrlsFromManifest(manifest));
}

async function cacheStaticUrls(cache, urls) {
  await Promise.allSettled(
    urls.map((url) => cache.add(new Request(url, { cache: "reload" }))),
  );
}

function buildAssetUrlsFromManifest(manifest) {
  if (!manifest || typeof manifest !== "object") {
    return [];
  }

  const urls = new Set();

  for (const entry of Object.values(manifest)) {
    collectBuildUrl(urls, entry?.file);

    for (const asset of entry?.assets || []) {
      collectBuildUrl(urls, asset);
    }

    for (const css of entry?.css || []) {
      collectBuildUrl(urls, css);
    }
  }

  return [...urls];
}

function collectBuildUrl(urls, path) {
  if (typeof path !== "string" || path.trim() === "") {
    return;
  }

  urls.add(`/build/${path.replace(/^\/+/, "")}`);
}

async function cleanupCaches() {
  const keys = await caches.keys();

  await Promise.all(
    keys
      .filter((key) => key.startsWith(CACHE_PREFIX) && key !== SHELL_CACHE)
      .map((key) => caches.delete(key)),
  );
}

function shouldBypassRequest(request) {
  if (!request || request.method !== "GET") {
    return true;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return true;
  }

  return isPrivatePath(url.pathname);
}

function isPrivatePath(pathname) {
  return (
    pathname === "/api" ||
    pathname.startsWith("/api/") ||
    pathname === "/dav" ||
    pathname.startsWith("/dav/")
  );
}

function isNavigationRequest(request) {
  return request.mode === "navigate";
}

function isStaticAssetRequest(request) {
  const url = new URL(request.url);

  return (
    APP_SHELL_URLS.includes(url.pathname) ||
    url.pathname === "/build/manifest.json" ||
    url.pathname.startsWith("/build/") ||
    url.pathname.startsWith("/images/icons/")
  );
}

async function handleNavigationRequest(request) {
  try {
    const response = await fetch(request);

    if (response.ok) {
      const cache = await caches.open(SHELL_CACHE);
      await cache.put("/", response.clone());
    }

    return response;
  } catch {
    const cache = await caches.open(SHELL_CACHE);
    const cachedShell = await cache.match("/");

    return (
      cachedShell ||
      new Response("Davvy is offline and the app shell is not cached yet.", {
        status: 503,
        headers: { "Content-Type": "text/plain; charset=utf-8" },
      })
    );
  }
}

async function handleStaticAssetRequest(request) {
  const cache = await caches.open(SHELL_CACHE);
  const cached = await cache.match(request, { ignoreSearch: true });

  if (cached) {
    return cached;
  }

  const response = await fetch(request);

  if (response.ok) {
    await cache.put(request, response.clone());
  }

  return response;
}
