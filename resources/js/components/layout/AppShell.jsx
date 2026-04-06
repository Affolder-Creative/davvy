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
            <div className="order-1 grid w-full grid-cols-2 items-stretch gap-2 md:order-2 md:flex md:w-auto md:flex-nowrap md:justify-end">
              <Link
                className={`${location.pathname === "/" ? "tab tab-active" : "tab"} ${
                  auth.contactManagementEnabled ? "col-span-1" : "col-span-2"
                } min-w-0 text-center md:col-auto md:flex-none`}
                to="/"
              >
                {t("tabs.dashboard")}
              </Link>
              {auth.contactManagementEnabled ? (
                <Link
                  className={`${location.pathname === "/contacts" ? "tab tab-active" : "tab"} col-span-1 min-w-0 text-center md:col-auto md:flex-none`}
                  to="/contacts"
                >
                  {t("tabs.contacts")}
                </Link>
              ) : null}
              {auth.contactChangeModerationEnabled ? (
                <Link
                  className={`${onReviewQueuePage ? "tab tab-active" : "tab"} inline-flex col-span-2 min-w-0 items-center justify-center gap-1.5 md:col-auto md:flex-none`}
                  to="/review-queue"
                >
                  <span className="text-center leading-tight">
                    {t("tabs.reviewQueue")}
                  </span>
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
            <svg
              class="sponsor-btn-icon"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
            >
              <g
                fill="none"
                stroke="currentColor"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.5"
              >
                <path d="M19.5 12.572L12 20l-7.5-7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572"></path>
                <path d="M12 6L8.707 9.293a1 1 0 0 0 0 1.414l.543.543c.69.69 1.81.69 2.5 0l1-1a3.182 3.182 0 0 1 4.5 0l2.25 2.25m-7 3l2 2M15 13l2 2"></path>
              </g>
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
