import React, { Suspense, lazy, useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
  BrowserRouter,
  Navigate,
  Route,
  Routes,
} from "react-router-dom";
import { I18nextProvider, useTranslation } from "react-i18next";
import ProtectedRoute from "./components/auth/ProtectedRoute";
import useAuthState from "./components/auth/useAuthState";
import FullPageState from "./components/common/FullPageState";
import PwaStatusBanner from "./components/common/PwaStatusBanner";
import { ToastProvider } from "./components/common/ToastProvider";
import useThemePreference from "./components/theme/useThemePreference";
import i18n, { setI18nLocale } from "./i18n";
import { api, setApiLocale } from "./lib/api";
import { useNetworkStatus } from "./lib/networkStatus";
import {
  activateWaitingServiceWorker,
  registerDavvyServiceWorker,
} from "./lib/pwa";

const LoginPage = lazy(() =>
  import("./routes/AuthPageRoutes").then((module) => ({
    default: module.LoginPageRoute,
  })),
);
const LoginTwoFactorPage = lazy(() =>
  import("./routes/AuthPageRoutes").then((module) => ({
    default: module.LoginTwoFactorPageRoute,
  })),
);
const RegisterPage = lazy(() =>
  import("./routes/AuthPageRoutes").then((module) => ({
    default: module.RegisterPageRoute,
  })),
);
const VerifyEmailPage = lazy(() =>
  import("./routes/AuthPageRoutes").then((module) => ({
    default: module.VerifyEmailPageRoute,
  })),
);
const InviteAcceptPage = lazy(() =>
  import("./routes/AuthPageRoutes").then((module) => ({
    default: module.InviteAcceptPageRoute,
  })),
);
const DashboardPage = lazy(() => import("./routes/DashboardPageRoute"));
const ContactsPage = lazy(() => import("./routes/ContactsPageRoute"));
const ContactChangeQueuePage = lazy(() =>
  import("./routes/ContactChangeQueuePageRoute"),
);
const AdminPage = lazy(() => import("./routes/AdminPageRoute"));
const ProfilePage = lazy(() => import("./routes/ProfilePageRoute"));

function RouteLoader({ children }) {
  const { t } = useTranslation("common");

  return (
    <Suspense fallback={<FullPageState label={t("states.loadingApp")} />}>
      {children}
    </Suspense>
  );
}

function App() {
  const { t } = useTranslation("common");
  const theme = useThemePreference();
  const { isOnline } = useNetworkStatus();
  const { auth, value } = useAuthState({
    api,
  });
  const [waitingServiceWorker, setWaitingServiceWorker] = useState(null);
  const [offlineReady, setOfflineReady] = useState(false);
  const updateReloadRequested = useRef(false);

  useEffect(() => {
    let active = true;

    const onControllerChange = () => {
      if (!updateReloadRequested.current) {
        return;
      }

      window.location.reload();
    };

    navigator.serviceWorker?.addEventListener(
      "controllerchange",
      onControllerChange,
    );

    void registerDavvyServiceWorker({
      onUpdateAvailable: (registration) => {
        if (!active) {
          return;
        }

        setWaitingServiceWorker(registration);
      },
      onOfflineReady: () => {
        if (!active) {
          return;
        }

        setOfflineReady(true);
      },
    });

    return () => {
      active = false;
      navigator.serviceWorker?.removeEventListener(
        "controllerchange",
        onControllerChange,
      );
    };
  }, []);

  useEffect(() => {
    setApiLocale(auth.locale, {
      supportedLocales: auth.supportedLocales,
      fallbackLocale: auth.fallbackLocale,
    });

    void setI18nLocale(auth.locale, {
      supportedLocales: auth.supportedLocales,
      fallbackLocale: auth.fallbackLocale,
    });
  }, [auth.locale, auth.supportedLocales, auth.fallbackLocale]);

  const pwaStatusBanner = (
    <PwaStatusBanner
      isOnline={isOnline}
      updateAvailable={Boolean(waitingServiceWorker)}
      offlineReady={offlineReady}
      onActivateUpdate={() => {
        updateReloadRequested.current = true;
        activateWaitingServiceWorker(waitingServiceWorker);
      }}
      onDismissOfflineReady={() => setOfflineReady(false)}
    />
  );

  if (auth.loading) {
    return (
      <>
        {pwaStatusBanner}
        <FullPageState label={t("states.loadingApp")} />
      </>
    );
  }

  return (
    <>
      {pwaStatusBanner}
      <Routes>
        <Route
          path="/login"
          element={
            <RouteLoader>
              <LoginPage auth={value} theme={theme} />
            </RouteLoader>
          }
        />
        <Route
          path="/login/2fa"
          element={
            <RouteLoader>
              <LoginTwoFactorPage auth={value} theme={theme} />
            </RouteLoader>
          }
        />
        <Route
          path="/register"
          element={
            <RouteLoader>
              <RegisterPage auth={value} theme={theme} />
            </RouteLoader>
          }
        />
        <Route
          path="/verify-email"
          element={
            <RouteLoader>
              <VerifyEmailPage auth={value} theme={theme} />
            </RouteLoader>
          }
        />
        <Route
          path="/invite"
          element={
            <RouteLoader>
              <InviteAcceptPage auth={value} theme={theme} />
            </RouteLoader>
          }
        />
        <Route
          path="/"
          element={
            <ProtectedRoute auth={value}>
              <RouteLoader>
                <DashboardPage auth={value} theme={theme} />
              </RouteLoader>
            </ProtectedRoute>
          }
        />
        <Route
          path="/contacts"
          element={
            <ProtectedRoute auth={value}>
              {value.contactManagementEnabled ? (
                <RouteLoader>
                  <ContactsPage auth={value} theme={theme} />
                </RouteLoader>
              ) : (
                <Navigate to="/" replace />
              )}
            </ProtectedRoute>
          }
        />
        <Route
          path="/review-queue"
          element={
            <ProtectedRoute auth={value}>
              {value.contactChangeModerationEnabled ? (
                <RouteLoader>
                  <ContactChangeQueuePage auth={value} theme={theme} />
                </RouteLoader>
              ) : (
                <Navigate to="/" replace />
              )}
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin"
          element={
            <ProtectedRoute auth={value} adminOnly>
              <RouteLoader>
                <AdminPage auth={value} theme={theme} />
              </RouteLoader>
            </ProtectedRoute>
          }
        />
        <Route
          path="/profile"
          element={
            <ProtectedRoute auth={value}>
              <RouteLoader>
                <ProfilePage auth={value} theme={theme} />
              </RouteLoader>
            </ProtectedRoute>
          }
        />
        <Route
          path="*"
          element={<Navigate to={auth.user ? "/" : "/login"} replace />}
        />
      </Routes>
    </>
  );
}

const mountNode = document.getElementById("app");

if (mountNode) {
  createRoot(mountNode).render(
    <I18nextProvider i18n={i18n}>
      <ToastProvider>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </ToastProvider>
    </I18nextProvider>,
  );
}

export default App;
