import { SUPPORTED_LOCALES } from "../../lib/locale";

/**
 * @typedef {{name: string, url: string}} SponsorshipLink
 */

/**
 * @typedef {{
 *   loading: boolean,
 *   user: object|null,
 *   registrationEnabled: boolean,
 *   registrationApprovalRequired: boolean,
 *   ownerShareManagementEnabled: boolean,
 *   davCompatibilityModeEnabled: boolean,
 *   contactManagementEnabled: boolean,
 *   contactChangeModerationEnabled: boolean,
 *   twoFactorEnforcementEnabled: boolean,
 *   twoFactorGracePeriodDays: number,
 *   twoFactorEnabled: boolean,
 *   twoFactorSetupRequired: boolean,
 *   twoFactorMandated: boolean,
 *   twoFactorGraceExpiresAt: string|null,
 *   sponsorship: {enabled: boolean, links: SponsorshipLink[]}
 * }} AuthState
 */

function createDefaultSponsorship() {
  return {
    enabled: false,
    links: [],
  };
}

function normalizeLocales(rawLocales, fallback = "en") {
  const locales = Array.isArray(rawLocales)
    ? rawLocales
        .map((locale) => String(locale ?? "").trim().toLowerCase())
        .filter(Boolean)
    : [];

  if (locales.length === 0) {
    return [fallback];
  }

  return [...new Set(locales)];
}

/**
 * Creates an initial loading auth state used before bootstrap requests complete.
 *
 * @returns {AuthState}
 */
export function createDefaultAuthState() {
  return {
    loading: true,
    user: null,
    registrationEnabled: false,
    registrationApprovalRequired: false,
    emailVerificationRequired: false,
    ownerShareManagementEnabled: false,
    davCompatibilityModeEnabled: false,
    contactManagementEnabled: false,
    contactChangeModerationEnabled: false,
    twoFactorEnforcementEnabled: false,
    twoFactorGracePeriodDays: 14,
    twoFactorEnabled: false,
    twoFactorSetupRequired: false,
    twoFactorMandated: false,
    twoFactorGraceExpiresAt: null,
    locale: "en",
    supportedLocales: [...SUPPORTED_LOCALES],
    fallbackLocale: "en",
    sponsorship: createDefaultSponsorship(),
  };
}

/**
 * Creates an unauthenticated auth state once bootstrap has completed.
 *
 * @returns {AuthState}
 */
export function createSignedOutAuthState() {
  return {
    ...createDefaultAuthState(),
    loading: false,
  };
}

/**
 * Normalizes the public sponsorship config payload.
 *
 * @param {unknown} rawConfig
 * @returns {{enabled: boolean, links: SponsorshipLink[]}}
 */
export function parseSponsorshipConfig(rawConfig) {
  if (!rawConfig || typeof rawConfig !== "object") {
    return createDefaultSponsorship();
  }

  const links = Array.isArray(rawConfig.links)
    ? rawConfig.links
        .filter((item) => item && typeof item === "object")
        .map((item) => ({
          name: String(item.name ?? "").trim(),
          url: String(item.url ?? "").trim(),
        }))
        .filter(
          (item) => item.name !== "" && /^https?:\/\/\S+$/i.test(item.url),
        )
    : [];

  return {
    enabled: Boolean(rawConfig.enabled) && links.length > 0,
    links,
  };
}

/**
 * Maps backend auth/public-config payloads into frontend auth state.
 *
 * @param {unknown} payload
 * @param {{user?: object|null}} [options]
 * @returns {AuthState}
 */
export function buildAuthStateFromPayload(payload, { user = null } = {}) {
  const source = payload && typeof payload === "object" ? payload : {};
  const fallbackLocale = String(source.fallback_locale ?? "en")
    .trim()
    .toLowerCase() || "en";
  const supportedLocales = normalizeLocales(
    source.supported_locales,
    fallbackLocale,
  );
  const localeCandidate = String(source.locale ?? fallbackLocale)
    .trim()
    .toLowerCase()
    .replace(/_/g, "-");
  const localePrimary = localeCandidate.split("-")[0];
  const locale = supportedLocales.includes(localeCandidate)
    ? localeCandidate
    : supportedLocales.includes(localePrimary)
      ? localePrimary
      : fallbackLocale;

  return {
    loading: false,
    user,
    registrationEnabled: !!source.registration_enabled,
    registrationApprovalRequired: !!source.registration_approval_required,
    emailVerificationRequired: !!source.email_verification_required,
    ownerShareManagementEnabled: !!source.owner_share_management_enabled,
    davCompatibilityModeEnabled: !!source.dav_compatibility_mode_enabled,
    contactManagementEnabled: !!source.contact_management_enabled,
    contactChangeModerationEnabled: !!source.contact_change_moderation_enabled,
    twoFactorEnforcementEnabled: !!source.two_factor_enforcement_enabled,
    twoFactorGracePeriodDays: Number(source.two_factor_grace_period_days || 14),
    twoFactorEnabled: !!source.two_factor_enabled,
    twoFactorSetupRequired: !!source.two_factor_setup_required,
    twoFactorMandated: !!source.two_factor_mandated,
    twoFactorGraceExpiresAt: source.two_factor_grace_expires_at || null,
    locale,
    supportedLocales,
    fallbackLocale,
    sponsorship: parseSponsorshipConfig(source.sponsorship),
  };
}
