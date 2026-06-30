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
    return {
      title: "Davvy",
      body: event.data.text(),
      data: {},
    };
  }
}

function normalizeNotificationPayload(payload) {
  const declarative =
    payload.notification && typeof payload.notification === "object"
      ? payload.notification
      : {};
  const payloadData =
    payload.data && typeof payload.data === "object" ? payload.data : {};
  const declarativeData =
    declarative.data && typeof declarative.data === "object"
      ? declarative.data
      : {};
  const data = {
    ...payloadData,
    ...declarativeData,
  };
  const badgeCount = normalizeBadgeCount(
    data.badge_count ??
      declarative.app_badge ??
      payload.app_badge ??
      payload.badge_count,
  );
  const targetUrl = normalizeTargetUrl(
    declarative.navigate ?? data.url ?? payload.url,
  );

  return {
    title: declarative.title || payload.title || "Davvy",
    badgeCount,
    options: {
      body: declarative.body || payload.body || "",
      icon: declarative.icon || payload.icon || "/images/icons/icon-192.png",
      badge: declarative.badge || payload.badge || "/images/icons/icon-192.png",
      tag:
        declarative.tag ||
        payload.tag ||
        data.type ||
        "davvy-notification",
      data: {
        ...data,
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
