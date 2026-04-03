import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useLocation, useNavigate } from "react-router-dom";

/**
 * Renders the App Shell.
 *
 * @param {object} props
 * @returns {JSX.Element}
 */
export default function AppShell({
  auth,
  theme,
  children,
  api,
  ThemeControl,
  SponsorshipLinkIcon,
}) {
  const { t } = useTranslation("shell");
  const navigate = useNavigate();
  const location = useLocation();
  const onAdminPage = location.pathname === "/admin";
  const onReviewQueuePage = location.pathname === "/review-queue";
  const [reviewQueueCount, setReviewQueueCount] = useState(0);
  const [mobileAccountMenuOpen, setMobileAccountMenuOpen] = useState(false);
  const [sponsorModalOpen, setSponsorModalOpen] = useState(false);
  const sponsorLinks = Array.isArray(auth.sponsorship?.links)
    ? auth.sponsorship.links
    : [];
  const showSponsorButton =
    Boolean(auth.sponsorship?.enabled) && sponsorLinks.length > 0;

  const logout = async () => {
    setMobileAccountMenuOpen(false);
    setSponsorModalOpen(false);
    await api.post("/api/auth/logout");
    auth.setAuth((current) => ({
      ...current,
      loading: false,
      user: null,
    }));
    navigate("/login");
  };

  useEffect(() => {
    if (!auth.user || !auth.contactChangeModerationEnabled) {
      setReviewQueueCount(0);
      return undefined;
    }

    let active = true;

    const refreshReviewQueueCount = async () => {
      try {
        const response = await api.get("/api/contact-change-requests/summary");
        if (!active) {
          return;
        }

        setReviewQueueCount(Number(response.data?.needs_review_count || 0));
      } catch {
        if (!active) {
          return;
        }

        setReviewQueueCount(0);
      }
    };

    void refreshReviewQueueCount();

    const onQueueUpdated = () => {
      void refreshReviewQueueCount();
    };

    window.addEventListener("review-queue-updated", onQueueUpdated);
    const timer = window.setInterval(() => {
      void refreshReviewQueueCount();
    }, 30000);

    return () => {
      active = false;
      window.removeEventListener("review-queue-updated", onQueueUpdated);
      window.clearInterval(timer);
    };
  }, [auth.contactChangeModerationEnabled, auth.user, location.pathname]);

  useEffect(() => {
    setMobileAccountMenuOpen(false);
    setSponsorModalOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!sponsorModalOpen) {
      return undefined;
    }

    const onKeyDown = (event) => {
      if (event.key === "Escape") {
        setSponsorModalOpen(false);
      }
    };

    window.addEventListener("keydown", onKeyDown);

    return () => window.removeEventListener("keydown", onKeyDown);
  }, [sponsorModalOpen]);

  const reviewQueueCountLabel =
    reviewQueueCount > 99 ? "99+" : String(reviewQueueCount);

  return (
    <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <header className="surface fade-up rounded-3xl p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <Link className="block" to="/">
              <p className="text-xs font-bold uppercase tracking-[0.24em] text-app-accent">
                {t("app.brand")}
              </p>
            </Link>
            <Link className="block" to="/">
              <h1 className="text-2xl font-bold text-app-strong">
                {t("app.subtitle")}
              </h1>
            </Link>
            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-2">
              <p className="text-sm text-app-muted">
                {t("session.signedInAs", { email: auth.user.email })}
              </p>
            </div>
          </div>
          <nav className="flex w-full flex-col gap-3 md:w-auto md:items-end">
            <div className="order-1 flex w-full items-center gap-2 overflow-x-auto pb-1 md:order-2 md:w-auto md:justify-end md:overflow-visible md:pb-0">
              <Link
                className={`${location.pathname === "/" ? "tab tab-active" : "tab"} shrink-0`}
                to="/"
              >
                {t("tabs.dashboard")}
              </Link>
              {auth.contactManagementEnabled ? (
                <Link
                  className={`${location.pathname === "/contacts" ? "tab tab-active" : "tab"} shrink-0`}
                  to="/contacts"
                >
                  {t("tabs.contacts")}
                </Link>
              ) : null}
              {auth.contactChangeModerationEnabled ? (
                <Link
                  className={`${onReviewQueuePage ? "tab tab-active" : "tab"} inline-flex shrink-0 items-center gap-1.5`}
                  to="/review-queue"
                >
                  <span>{t("tabs.reviewQueue")}</span>
                  {reviewQueueCount > 0 ? (
                    <span className="rounded-full border border-app-accent-edge bg-app-surface px-2 py-0.5 text-[10px] font-semibold leading-none text-app-accent">
                      {reviewQueueCountLabel}
                    </span>
                  ) : null}
                </Link>
              ) : null}
            </div>
            <div className="order-2 md:hidden">
              <button
                className="btn-outline w-full justify-between"
                type="button"
                onClick={() => setMobileAccountMenuOpen((current) => !current)}
                aria-expanded={mobileAccountMenuOpen}
                aria-label={t("account.toggleMenu")}
              >
                <span>{t("account.label")}</span>
                <svg
                  aria-hidden="true"
                  className={`h-4 w-4 transition-transform ${
                    mobileAccountMenuOpen ? "rotate-180" : ""
                  }`}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M6 9l6 6 6-6" />
                </svg>
              </button>
              {mobileAccountMenuOpen ? (
                <div className="mt-2 grid gap-2 rounded-2xl border border-app-edge bg-app-surface p-2">
                  <Link
                    className={`${location.pathname === "/profile" ? "tab tab-active" : "tab"} inline-flex items-center justify-between gap-2`}
                    to="/profile"
                    onClick={() => setMobileAccountMenuOpen(false)}
                  >
                    <span className="truncate">{auth.user.name}</span>
                    <svg
                      aria-hidden="true"
                      className="h-4 w-4"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="1.75"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    >
                      <circle cx="12" cy="8" r="4" />
                      <path d="M5 20c1.6-3.3 4-5 7-5s5.4 1.7 7 5" />
                    </svg>
                  </Link>
                  {auth.user.role === "admin" ? (
                    <Link
                      className={
                        onAdminPage
                          ? "btn-outline btn-outline-sm admin-cta admin-cta-active group justify-center"
                          : "btn-outline btn-outline-sm admin-cta group justify-center"
                      }
                      to="/admin"
                      onClick={() => setMobileAccountMenuOpen(false)}
                      aria-label={t("admin.openControlCenter")}
                      title={t("admin.openControlCenter")}
                    >
                      <svg
                        aria-hidden="true"
                        className="h-4 w-4 opacity-85 transition group-hover:opacity-100"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.8"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      >
                        <path d="M12 3l7 3v6c0 4.4-2.8 8.2-7 9-4.2-.8-7-4.6-7-9V6l7-3z" />
                        <path d="M9.5 12.5l1.7 1.7 3.3-3.6" />
                      </svg>
                      <span>{t("admin.controlCenter")}</span>
                    </Link>
                  ) : null}
                  <button
                    className="btn-outline w-full text-app-danger"
                    type="button"
                    onClick={logout}
                  >
                    {t("actions.signOut")}
                  </button>
                </div>
              ) : null}
            </div>
            <div className="order-3 hidden items-center gap-2 md:order-1 md:flex md:justify-end">
              {auth.user.role === "admin" ? (
                <Link
                  className={
                    onAdminPage
                      ? "btn-outline btn-outline-sm admin-cta admin-cta-active group"
                      : "btn-outline btn-outline-sm admin-cta group"
                  }
                  to="/admin"
                  aria-label={t("admin.openControlCenter")}
                  title={t("admin.openControlCenter")}
                >
                  <svg
                    aria-hidden="true"
                    className="h-4 w-4 opacity-85 transition group-hover:opacity-100"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <path d="M12 3l7 3v6c0 4.4-2.8 8.2-7 9-4.2-.8-7-4.6-7-9V6l7-3z" />
                    <path d="M9.5 12.5l1.7 1.7 3.3-3.6" />
                  </svg>
                  <span>{t("admin.controlCenter")}</span>
                  {onAdminPage ? null : (
                    <svg
                      aria-hidden="true"
                      className="h-3.5 w-3.5 transition group-hover:translate-x-0.5"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    >
                      <path d="M5 12h14" />
                      <path d="M13 6l6 6-6 6" />
                    </svg>
                  )}
                </Link>
              ) : null}
              <Link
                className={`${location.pathname === "/profile" ? "tab tab-active" : "tab"} min-w-0 inline-flex items-center gap-1.5`}
                to="/profile"
                aria-label={t("tabs.profile")}
                title={t("tabs.profile")}
              >
                <span className="max-w-24 truncate sm:max-w-36">
                  {auth.user.name}
                </span>
                <svg
                  aria-hidden="true"
                  className="h-4 w-4"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <circle cx="12" cy="8" r="4" />
                  <path d="M5 20c1.6-3.3 4-5 7-5s5.4 1.7 7 5" />
                </svg>
              </Link>
              <button className="btn-outline" onClick={logout}>
                {t("actions.signOut")}
              </button>
            </div>
          </nav>
        </div>
      </header>
      <div className="mt-6">{children}</div>
      <div
        className={`mt-6 flex flex-wrap items-center gap-3 ${
          showSponsorButton ? "justify-between" : "justify-end"
        }`}
      >
        {showSponsorButton ? (
          <button
            type="button"
            className="sponsor-btn"
            onClick={() => setSponsorModalOpen(true)}
            aria-haspopup="dialog"
            aria-expanded={sponsorModalOpen}
            aria-controls="sponsor-modal"
          >
            {/* <svg
              aria-hidden="true"
              className="sponsor-btn-icon"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" />
            </svg>*/}
            {/* <svg
              aria-hidden="true"
              className="sponsor-btn-icon"
              viewBox="0 -960 960 960"
              fill="currentColor"
            >
              <path d="M468.08-156.15q8.51 0 17.38-3.62 8.87-3.61 14.26-9.51l314.77-314.93q15.23-15.23 23.83-31.59 8.6-16.37 8.6-35.82 0-19.74-8.6-38.34-8.6-18.6-23.83-33.76L661.41-776.8q-15.15-15.23-31.45-22.67-16.29-7.45-36.04-7.45-19.44 0-36.19 7.45-16.76 7.44-31.22 22.67l-36.95 36.95 76.11 76.34q11.1 11.18 17.92 26.43 6.82 15.26 6.82 31.46 0 30.11-20.83 50.61-20.84 20.5-50.43 20.5-17.18 0-31.27-5.03-14.09-5.02-25.57-16.1l-75.93-76.26-174.64 174.64q-6.38 6.39-9.75 14.96t-3.37 16.93q0 15.7 9.97 25.46 9.97 9.76 25.19 9.76 8.89 0 17.6-3.95 8.72-3.95 14.11-9.34l127.59-127.59 24.1 24.11L289.82-380q-6.38 6.9-9.76 15.27-3.37 8.37-3.37 17.04 0 14.51 10.44 24.95 10.43 10.43 24.95 10.43 8.66 0 17.38-3.61 8.72-3.62 14.1-9.52l136.82-136.74 24.11 24.1L367.9-301.92q-5.46 5.89-9.3 14.66-3.83 8.76-3.83 17.64 0 14.52 10.44 24.95 10.43 10.44 24.94 10.44 8.67 0 16.89-3.37t14.6-9.76L558.46-384.1l24.1 24.1-136.82 136.82q-6.38 6.39-9.75 15.53t-3.37 16.88q0 15.59 10.44 25.1 10.45 9.52 25.02 9.52Zm-.04 33.84q-29.76 0-50.82-22.32-21.07-22.32-18.37-55.91-34 .98-56.62-19.72-22.61-20.69-21.46-58.28-37.08.39-58.42-20.93-21.35-21.32-19.43-57.15-32.71.9-55.43-17.53-22.72-18.44-22.72-51.62 0-14.92 5.96-29.89 5.95-14.97 16.68-25.93l198.97-198.9 97 97q6.52 6.59 15.74 10.99t20.11 4.4q14.49 0 25.95-10.91 11.46-10.91 11.46-26.53 0-9.59-4.24-18.1-4.25-8.51-10.84-15.54L403.95-776.8q-15.16-15.23-31.83-22.67-16.68-7.45-36.43-7.45-19.44 0-35.43 7.45-15.98 7.44-31.17 22.58L144.05-651.72q-14.64 14.64-22.49 33.21-7.84 18.56-7.64 40.43-.3 15.46 4.49 29.91 4.8 14.45 12.74 26.58l-25.94 25.44q-10.54-15.93-17.7-37.94-7.15-22.01-7.51-44.99-.36-28.05 10.03-53.16 10.38-25.12 29.84-44.58L243.95-800.9q20.1-20.02 43.08-29.87 22.98-9.85 49.45-9.85 26.47 0 49.12 9.85 22.66 9.85 42.68 29.87l36.95 36.95 36.95-36.95q20.1-20.02 42.7-29.87 22.59-9.85 49.06-9.85 26.47 0 49.51 9.85t43.06 29.87l152.08 152.08q20.03 20.03 31.1 45.51 11.08 25.48 11.08 51.79t-11.08 48.97q-11.07 22.65-31.1 42.68L523.82-145.18q-11.79 12.31-25.91 17.59-14.11 5.28-29.87 5.28Zm-127.6-500.41Z" />
            </svg>*/}
            <svg
              aria-hidden="true"
              className="sponsor-btn-icon"
              viewBox="0 0 26.17 23.24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeMiterlimit="10"
            >
              <path d="M1.45,10.95S0,7.85,1.93,6.02,6.03,1.97,6.03,1.97c0,0,2.21-2.35,4.89.24s3.86,3.77,3.86,3.77c0,0,1.79,1.5.56,3.01s-2.87-.14-2.87-.14l-2.49-2.54-5.65,5.46s-1.3,1.41-.52,2.58,2.49.42,2.49.42l4.14-4.19" />
              <path d="M6.31,14.77c-.92,1.23.14,3.03,1.66,2.73.57-.13.37-.16,1.1-.81,1.03-1,2.83-2.72,3.8-3.66" />
              <path d="M8.35,17.36c-.47,2.06,1.47,3.18,2.86,2.06,1.16-1.06,3.39-3.4,3.95-3.94" />
              <path d="M11.25,19.38c-.67,2.01,1.5,3.89,3.13,2.23,2.17-2.12,8.37-8.24,10.05-9.93,2.13-2.7-.37-5.28-2.45-7.1-1.47-1.32-2.01-2.08-3.24-2.9-2.17-1.26-4.81,0-6.41,1.88" />
            </svg>
            <span>{t("sponsor.button")}</span>
          </button>
        ) : null}
        <ThemeControl
          theme={theme.theme}
          setTheme={theme.setTheme}
          className="theme-control-inline"
        />
      </div>

      {showSponsorButton && sponsorModalOpen ? (
        <div
          className="fixed inset-0 z-50 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="sponsor-modal-title"
          id="sponsor-modal"
        >
          <button
            type="button"
            className="absolute inset-0 bg-black/50"
            aria-label={t("sponsor.closeLinks")}
            onClick={() => setSponsorModalOpen(false)}
          />
          <div className="surface relative mx-auto mt-[10vh] w-full max-w-md rounded-2xl p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3
                  id="sponsor-modal-title"
                  className="text-lg font-semibold text-app-strong"
                >
                  {t("sponsor.title")}
                </h3>
                <p className="mt-1 text-sm text-app-muted">
                  {t("sponsor.subtitle")}
                </p>
              </div>
              <button
                type="button"
                className="btn-outline btn-outline-sm"
                onClick={() => setSponsorModalOpen(false)}
              >
                {t("sponsor.close")}
              </button>
            </div>
            <div className="mt-4 grid gap-2">
              {sponsorLinks.map((link) => (
                <a
                  key={link.url}
                  className="sponsor-modal-link"
                  href={link.url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <span className="sponsor-modal-link-main">
                    <SponsorshipLinkIcon name={link.name} url={link.url} />
                    <span className="sponsor-modal-link-label">
                      {link.name}
                    </span>
                  </span>
                  <svg
                    aria-hidden="true"
                    className="h-4 w-4"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <path d="M7 17 17 7" />
                    <path d="M7 7h10v10" />
                  </svg>
                </a>
              ))}
            </div>
          </div>
        </div>
      ) : null}
    </main>
  );
}
