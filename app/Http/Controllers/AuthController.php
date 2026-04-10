<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserAppPassword;
use App\Services\RegistrationSettingsService;
use App\Services\Security\AppPasswordService;
use App\Services\Security\PendingTwoFactorLoginService;
use App\Services\Security\TwoFactorService;
use App\Services\Security\TwoFactorSettingsService;
use App\Services\SponsorshipLinksService;
use App\Services\UserOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const TWO_FACTOR_PENDING_SETUP_SESSION_KEY = 'auth.pending_two_factor_secret';

    /**
     * Create a new auth controller instance.
     *
     * @param  OpenPanelAnalyticsService  $analytics
     * @return void
     */
    public function __construct(
        private readonly RegistrationSettingsService $registrationSettings,
        private readonly SponsorshipLinksService $sponsorshipLinks,
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorSettingsService $twoFactorSettings,
        private readonly PendingTwoFactorLoginService $pendingTwoFactorLogin,
        private readonly AppPasswordService $appPasswords,
        private readonly UserOnboardingService $onboarding,
    ) {}

    /**
     * Register a new user account and return auth bootstrap data.
     */
    public function register(Request $request): JsonResponse
    {
        if (! $this->registrationSettings->isPublicRegistrationEnabled()) {
            abort(403, __('auth.public_registration_disabled'));
        }

        $email = Str::lower(trim((string) $request->input('email', '')));
        if ($email !== '') {
            $request->merge(['email' => $email]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $approvalRequired = $this->registrationSettings->isPublicRegistrationApprovalRequired();
        $verificationRequired = $this->onboarding->shouldRequirePublicEmailVerification();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Role::Regular,
            'locale' => app()->getLocale(),
            'email_verified_at' => $verificationRequired ? null : now(),
            'is_approved' => ! $approvalRequired,
            'approved_at' => $approvalRequired ? null : now(),
            'approved_by' => null,
        ]);

        if ($verificationRequired) {
            $verification = $this->onboarding->issueEmailVerification($user);
            $verificationSent = $this->onboarding->sendVerificationEmail(
                user: $user,
                verificationUrl: $verification['url'],
                expiresAt: $verification['expires_at'],
            );

            $payload = array_merge([
                'registration_pending_verification' => true,
                'registration_pending_approval' => $approvalRequired,
                'message' => $approvalRequired
                    ? __('auth.registration_submitted_verify_and_wait_approval')
                    : __('auth.registration_submitted_verify_before_signin'),
                'verification_email_sent' => $verificationSent,
            ], $this->publicSettingsPayload());

            if (
                ! $verificationSent
                && $this->onboarding->shouldExposeLinksWithoutMailer()
            ) {
                $payload['verification_url'] = $verification['url'];
            }

            return response()->json($payload, 202);
        }

        if ($approvalRequired) {
            return response()->json(
                array_merge([
                    'registration_pending_approval' => true,
                    'message' => __('auth.registration_submitted_pending_approval'),
                ], $this->publicSettingsPayload()),
                202
            );
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(
            array_merge(['user' => $user], $this->authenticatedSettingsPayload($user)),
            201,
        );
    }

    /**
     * Verify a public registration email token and sign in approved accounts.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $record = $this->onboarding->consumeEmailVerification($data['token']);
        if (! $record || ! $record->user) {
            abort(422, __('auth.verification_link_invalid_or_expired'));
        }

        $user = $record->user;

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $user->save();
        }

        if (! $user->is_approved) {
            return response()->json(array_merge([
                'registration_pending_approval' => true,
                'email_verified' => true,
                'message' => __('auth.email_verified_pending_approval'),
            ], $this->publicSettingsPayload()), 202);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $fresh = $user->fresh();

        return response()->json(
            array_merge([
                'email_verified' => true,
                'user' => $fresh,
            ], $this->authenticatedSettingsPayload($fresh)),
        );
    }

    /**
     * Accept an admin invitation token, set an initial password, and sign in the user.
     */
    public function acceptInvite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $record = $this->onboarding->consumeInvite($data['token']);
        if (! $record || ! $record->user) {
            abort(422, __('auth.invitation_link_invalid_or_expired'));
        }

        $user = $record->user;
        $user->password = $data['password'];
        $user->email_verified_at = now();
        $user->save();

        if (! $user->is_approved) {
            return response()->json(array_merge([
                'registration_pending_approval' => true,
                'message' => __('auth.invitation_accepted_pending_approval'),
            ], $this->publicSettingsPayload()), 202);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $fresh = $user->fresh();

        return response()->json(
            array_merge([
                'invitation_accepted' => true,
                'user' => $fresh,
            ], $this->authenticatedSettingsPayload($fresh)),
        );
    }

    /**
     * Return public feature configuration for unauthenticated clients.
     */
    public function publicConfig(): JsonResponse
    {
        return response()->json($this->publicSettingsPayload());
    }

    /**
     * Authenticate credentials and begin or complete sign-in.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower(trim((string) $data['email']));

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check((string) $data['password'], $user->password)) {
            return response()->json([
                'message' => __('auth.credentials_invalid'),
            ], 422);
        }

        if (! $user->is_approved) {
            return response()->json([
                'message' => __('auth.account_pending_approval'),
            ], 403);
        }

        if (
            $this->onboarding->shouldRequirePublicEmailVerification()
            && $user->email_verified_at === null
        ) {
            return response()->json([
                'message' => __('auth.verify_email_before_signin'),
            ], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->pendingTwoFactorLogin->start(
                request: $request,
                user: $user,
                remember: (bool) ($data['remember'] ?? false),
            );

            return response()->json([
                'two_factor_required' => true,
                'message' => __('auth.two_factor_code_required'),
                'challenge_expires_at' => now()->addMinutes(10)->toISOString(),
            ], 202);
        }

        Auth::login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return response()->json(
            array_merge(['user' => $request->user()], $this->authenticatedSettingsPayload($request->user())),
        );
    }

    /**
     * Return pending two-factor challenge metadata for sign-in.
     */
    public function loginTwoFactorStatus(Request $request): JsonResponse
    {
        return response()->json($this->pendingTwoFactorLogin->status($request));
    }

    /**
     * Verify a two-factor code and complete sign-in.
     */
    public function completeTwoFactorLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $this->pendingTwoFactorLogin->pendingUser($request);
        if (! $user) {
            return response()->json([
                'message' => __('auth.no_pending_two_factor_challenge'),
            ], 422);
        }

        $verified = $this->twoFactor->verifyTotpOrBackupCode($user, $data['code']);
        if (! $verified) {
            $this->pendingTwoFactorLogin->registerFailedAttempt($request);

            return response()->json([
                'message' => __('auth.invalid_authentication_code'),
            ], 422);
        }

        $remember = $this->pendingTwoFactorLogin->remember($request);
        $this->pendingTwoFactorLogin->clear($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json(
            array_merge(['user' => $request->user()], $this->authenticatedSettingsPayload($request->user())),
        );
    }

    /**
     * Log out the current user and invalidate the session.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->pendingTwoFactorLogin->clear($request);
        $request->session()->forget(self::TWO_FACTOR_PENDING_SETUP_SESSION_KEY);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * Return the authenticated user with feature flags.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(
            array_merge(['user' => $request->user()], $this->authenticatedSettingsPayload($request->user())),
        );
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => $data['password'],
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * Update the authenticated user's preferred locale.
     */
    public function updateLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in($this->supportedLocales())],
        ]);

        $user = $request->user();
        $user->locale = (string) $data['locale'];
        $user->save();
        $user->refresh();

        app()->setLocale((string) $user->locale);

        return response()->json(
            array_merge([
                'ok' => true,
                'user' => $user,
            ], $this->authenticatedSettingsPayload($user)),
        );
    }

    /**
     * Return current two-factor enrollment status for the user.
     */
    public function twoFactorStatus(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $graceDeadline = $this->twoFactorSettings->graceDeadlineFor($user);

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'mandated' => $this->twoFactorSettings->isEnforced(),
            'setup_required' => $this->twoFactorSettings->isSetupRequired($user),
            'grace_expires_at' => $graceDeadline?->toISOString(),
            'backup_codes_remaining' => is_array($user->two_factor_backup_codes)
                ? count($user->two_factor_backup_codes)
                : 0,
        ]);
    }

    /**
     * Start two-factor enrollment and return setup details.
     */
    public function startTwoFactorSetup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            abort(409, __('auth.two_factor_already_enabled'));
        }

        $setup = $this->twoFactor->beginSetup($user);
        $request->session()->put(self::TWO_FACTOR_PENDING_SETUP_SESSION_KEY, $setup['secret']);

        return response()->json([
            'secret' => $setup['secret'],
            'manual_key' => $setup['manual_key'],
            'otpauth_uri' => $setup['otpauth_uri'],
        ]);
    }

    /**
     * Enable two-factor authentication after code verification.
     */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            abort(409, __('auth.two_factor_already_enabled'));
        }

        $secret = (string) $request->session()->get(self::TWO_FACTOR_PENDING_SETUP_SESSION_KEY, '');
        if ($secret === '') {
            abort(422, __('auth.two_factor_setup_expired'));
        }

        if (! $this->twoFactor->verifyEnrollmentCode($secret, $data['code'])) {
            abort(422, __('auth.two_factor_code_invalid'));
        }

        $backupCodes = $this->twoFactor->enable($user, $secret);
        $request->session()->forget(self::TWO_FACTOR_PENDING_SETUP_SESSION_KEY);

        $fresh = $user->fresh();

        return response()->json([
            'enabled' => true,
            'backup_codes' => $backupCodes,
            'two_factor_setup_required' => $this->twoFactorSettings->isSetupRequired($fresh),
        ]);
    }

    /**
     * Disable two-factor authentication and clear related state.
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user()->fresh();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, __('auth.two_factor_not_enabled'));
        }

        if (! $this->twoFactor->verifyTotpOrBackupCode($user, $data['code'])) {
            abort(422, __('auth.invalid_authentication_code'));
        }

        $this->twoFactor->disable($user, revokeAppPasswords: true);

        return response()->json([
            'enabled' => false,
            'app_passwords_revoked' => true,
        ]);
    }

    /**
     * Regenerate two-factor backup codes.
     */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user()->fresh();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, __('auth.two_factor_not_enabled'));
        }

        if (! $this->twoFactor->verifyTotpOrBackupCode($user, $data['code'])) {
            abort(422, __('auth.invalid_authentication_code'));
        }

        $backupCodes = $this->twoFactor->regenerateBackupCodes($user);

        return response()->json([
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * List active app passwords for the current user.
     */
    public function listAppPasswords(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, __('auth.enable_two_factor_before_managing_app_passwords'));
        }

        $data = $this->appPasswords->activeFor($user)
            ->map(fn (UserAppPassword $password): array => [
                'id' => $password->id,
                'name' => $password->name,
                'token_prefix' => $password->token_prefix,
                'last_used_at' => $password->last_used_at?->toISOString(),
                'created_at' => $password->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Create a new app password for the current user.
     */
    public function createAppPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user()->fresh();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, __('auth.enable_two_factor_before_creating_app_passwords'));
        }

        if (! $this->twoFactor->verifyTotpOrBackupCode($user, $data['code'])) {
            abort(422, __('auth.invalid_authentication_code'));
        }

        $created = $this->appPasswords->create($user, $data['name']);
        /** @var UserAppPassword $record */
        $record = $created['record'];

        return response()->json([
            'id' => $record->id,
            'name' => $record->name,
            'token' => $created['token'],
            'token_prefix' => $record->token_prefix,
            'last_used_at' => $record->last_used_at?->toISOString(),
            'created_at' => $record->created_at?->toISOString(),
        ], 201);
    }

    /**
     * Revoke the specified app password for the current user.
     */
    public function revokeAppPassword(Request $request, UserAppPassword $appPassword): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user()->fresh();

        if (! $user->hasTwoFactorEnabled()) {
            abort(422, __('auth.enable_two_factor_before_managing_app_passwords'));
        }

        if ((int) $appPassword->user_id !== (int) $user->id) {
            abort(404);
        }

        if (! $this->twoFactor->verifyTotpOrBackupCode($user, $data['code'])) {
            abort(422, __('auth.invalid_authentication_code'));
        }

        $revoked = $this->appPasswords->revoke($user, (int) $appPassword->id);

        if (! $revoked) {
            abort(404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Return the public settings payload.
     *
     * @return array<string, mixed>
     */
    private function publicSettingsPayload(): array
    {
        return array_merge([
            'registration_enabled' => $this->registrationSettings->isPublicRegistrationEnabled(),
            'registration_approval_required' => $this->registrationSettings->isPublicRegistrationApprovalRequired(),
            'email_verification_required' => $this->onboarding->shouldRequirePublicEmailVerification(),
            'owner_share_management_enabled' => $this->registrationSettings->isOwnerShareManagementEnabled(),
            'dav_compatibility_mode_enabled' => $this->registrationSettings->isDavCompatibilityModeEnabled(),
            'contact_management_enabled' => $this->registrationSettings->isContactManagementEnabled(),
            'contact_change_moderation_enabled' => $this->registrationSettings->isContactChangeModerationEnabled(),
            'private_working_set_enabled' => $this->registrationSettings->isPrivateWorkingSetEnabled(),
            'two_factor_enforcement_enabled' => $this->twoFactorSettings->isEnforced(),
            'two_factor_grace_period_days' => $this->twoFactorSettings->gracePeriodDays(),
            'sponsorship' => $this->sponsorshipLinks->publicConfig(),
        ], $this->localePayload());
    }

    /**
     * Return the authenticated settings payload.
     *
     * @return array<string, mixed>
     */
    private function authenticatedSettingsPayload(User $user): array
    {
        $graceDeadline = $this->twoFactorSettings->graceDeadlineFor($user);

        return array_merge($this->publicSettingsPayload(), [
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'two_factor_setup_required' => $this->twoFactorSettings->isSetupRequired($user),
            'two_factor_mandated' => $this->twoFactorSettings->isEnforced(),
            'two_factor_grace_expires_at' => $graceDeadline?->toISOString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function localePayload(): array
    {
        $supportedLocales = $this->supportedLocales();
        $fallbackLocale = strtolower(trim((string) config('app.fallback_locale', 'en')));

        if (! in_array($fallbackLocale, $supportedLocales, true)) {
            $supportedLocales[] = $fallbackLocale;
        }

        $locale = strtolower(trim((string) app()->getLocale()));
        if (! in_array($locale, $supportedLocales, true)) {
            $locale = $fallbackLocale;
        }

        return [
            'locale' => $locale,
            'supported_locales' => $supportedLocales,
            'fallback_locale' => $fallbackLocale,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function supportedLocales(): array
    {
        $locales = config('app.supported_locales', ['en']);

        $normalized = collect(is_array($locales) ? $locales : [])
            ->map(fn (mixed $locale): string => strtolower(trim((string) $locale)))
            ->filter(fn (string $locale): bool => $locale !== '')
            ->unique()
            ->values()
            ->all();

        return $normalized === [] ? ['en'] : $normalized;
    }
}
