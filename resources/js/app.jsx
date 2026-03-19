import React, { Suspense, lazy, useEffect } from "react";
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
import useThemePreference from "./components/theme/useThemePreference";
import i18n, { setI18nLocale } from "./i18n";
import { api, setApiLocale } from "./lib/api";

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
    <Suspense fallback={<FullPageState label={t("loading_app")} />}>
      {children}
    </Suspense>
  );
}

function App() {
  const { t } = useTranslation("common");
  const theme = useThemePreference();
  const { auth, value } = useAuthState({
    api,
  });

  useEffect(() => {
    setApiLocale(auth.locale, {
      supportedLocales: auth.supportedLocales,
      fallbackLocale: auth.fallbackLocale,
    });

    setI18nLocale(auth.locale, {
      supportedLocales: auth.supportedLocales,
      fallbackLocale: auth.fallbackLocale,
    });
  }, [auth.locale, auth.supportedLocales, auth.fallbackLocale]);

  if (auth.loading) {
    return <FullPageState label={t("loading_app")} />;
  }

  return (
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
  );
}

const mountNode = document.getElementById("app");

if (mountNode) {
  createRoot(mountNode).render(
    <I18nextProvider i18n={i18n}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </I18nextProvider>,
  );
}

export default App;
