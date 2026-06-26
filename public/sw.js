self.addEventListener("push", (event) => {
  event.waitUntil(handlePush(event));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  event.waitUntil(openNotificationTarget(event.notification.data));
});

async function handlePush(event) {
  const payload = parsePushPayload(event);
  const title = payload.title || "Davvy";
  const options = {
    body: payload.body || "",
    icon: payload.icon || "/images/icons/icon-192.png",
    badge: payload.badge || "/images/icons/icon-192.png",
    tag: payload.tag || payload.data?.type || "davvy-notification",
    data: payload.data || {},
  };

  const badgeCount = Number(options.data.badge_count ?? payload.badge_count ?? 0);
  await updateBadge(badgeCount);
  await self.registration.showNotification(title, options);
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
