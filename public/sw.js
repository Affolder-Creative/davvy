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
