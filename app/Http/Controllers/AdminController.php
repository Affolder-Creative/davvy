<?php

namespace App\Http\Controllers;

use App\Enums\ContactChangeStatus;
use App\Enums\Role;
use App\Models\AddressBook;
use App\Models\AddressBookContactMilestoneCalendar;
use App\Models\AppSetting;
use App\Models\Calendar;
use App\Models\ContactChangeRequest;
use App\Models\User;
use App\Services\Backups\BackupRestoreDispatchService;
use App\Services\Backups\BackupRunDispatchService;
use App\Services\Backups\BackupSettingsService;
use App\Services\Contacts\ContactMilestoneCalendarService;
use App\Services\RegistrationSettingsService;
use App\Services\Security\TwoFactorService;
use App\Services\Security\TwoFactorSettingsService;
use App\Services\UserDeletionService;
use App\Services\UserOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AdminController extends Controller
{
    /**
     * Create a new admin controller instance.
     */
    public function __construct(
        private readonly RegistrationSettingsService $registrationSettings,
        private readonly ContactMilestoneCalendarService $milestoneCalendarService,
        private readonly BackupSettingsService $backupSettings,
        private readonly BackupRunDispatchService $backupRunDispatchService,
        private readonly BackupRestoreDispatchService $backupRestoreDispatchService,
        private readonly TwoFactorSettingsService $twoFactorSettings,
        private readonly TwoFactorService $twoFactor,
        private readonly UserOnboardingService $onboarding,
        private readonly UserDeletionService $userDeletionService,
    ) {}

    /**
     * Return users for the admin dashboard.
     */
    public function users(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'in:all,admin,regular'],
            'two_factor' => ['nullable', 'in:all,enabled,disabled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $roleFilter = (string) ($filters['role'] ?? 'all');
        $twoFactorFilter = (string) ($filters['two_factor'] ?? 'all');
        $perPage = (int) ($filters['per_page'] ?? 100);
        $page = (int) ($filters['page'] ?? 1);

        $query = User::query()
            ->withCount(['calendars', 'addressBooks'])
            ->orderBy('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($roleFilter === 'admin') {
            $query->where('role', Role::Admin->value);
        } elseif ($roleFilter === 'regular') {
            $query->where('role', Role::Regular->value);
        }

        if ($twoFactorFilter === 'enabled') {
            $query
                ->whereNotNull('two_factor_enabled_at')
                ->whereNotNull('two_factor_secret')
                ->where('two_factor_secret', '!=', '');
        } elseif ($twoFactorFilter === 'disabled') {
            $query->where(function ($builder): void {
                $builder
                    ->whereNull('two_factor_enabled_at')
                    ->orWhereNull('two_factor_secret')
                    ->orWhere('two_factor_secret', '');
            });
        }

        $paginator = $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends($request->query());

        $users = $paginator
            ->getCollection()
            ->map(function (User $user): array {
                $payload = $user->toArray();
                $payload['two_factor_enabled'] = $user->hasTwoFactorEnabled();

                return $payload;
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $users,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
            'filters' => [
                'q' => $search === '' ? null : $search,
                'role' => $roleFilter,
                'two_factor' => $twoFactorFilter,
            ],
        ]);
    }

    /**
     * Create an approved user account and issue a one-time invitation link.
     */
    public function createUser(Request $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->input('email', '')));
        if ($email !== '') {
            $request->merge(['email' => $email]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,regular'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(48),
            'role' => Role::from($data['role']),
            'locale' => app()->getLocale(),
            'email_verified_at' => null,
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $request->user()?->id,
        ]);

        $invitation = $this->onboarding->issueInvite($user);
        $invitationSent = $this->onboarding->sendInviteEmail(
            user: $user,
            inviteUrl: $invitation['url'],
            expiresAt: $invitation['expires_at'],
        );

        $payload = $user->toArray();
        $payload['invitation_sent'] = $invitationSent;
        $payload['invitation_expires_at'] = $invitation['expires_at']->toISOString();

        if (! $invitationSent && $this->onboarding->shouldExposeLinksWithoutMailer()) {
            $payload['invitation_url'] = $invitation['url'];
        }

        return response()->json($payload, 201);
    }

    /**
     * Delete a user account and related data.
     */
    public function destroyUser(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'confirmation_email' => ['required', 'string', 'email', 'max:255'],
            'transfer_owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $actorEmail = Str::lower(trim((string) ($actor?->email ?? '')));
        $confirmationEmail = Str::lower(trim((string) $data['confirmation_email']));
        if ($confirmationEmail !== $actorEmail) {
            abort(422, __('admin.type_account_email_to_confirm_deletion'));
        }

        if ($actor && (int) $actor->id === (int) $user->id) {
            abort(422, __('admin.cannot_delete_own_account'));
        }

        if ($user->isAdmin()) {
            $remainingAdminCount = User::query()
                ->where('role', Role::Admin->value)
                ->whereKeyNot($user->id)
                ->count();

            if ($remainingAdminCount === 0) {
                abort(422, __('admin.cannot_delete_last_admin_account'));
            }
        }

        $transferOwnerId = array_key_exists('transfer_owner_id', $data) && $data['transfer_owner_id'] !== null
            ? (int) $data['transfer_owner_id']
            : null;

        if ($transferOwnerId !== null && $transferOwnerId === (int) $user->id) {
            abort(422, __('admin.select_different_transfer_owner'));
        }

        $result = $this->userDeletionService->deleteUser(
            user: $user,
            transferOwnerId: $transferOwnerId,
        );

        return response()->json([
            'ok' => true,
            'deleted_user_id' => (int) $result['deleted_user_id'],
            'transferred_to_user_id' => $result['transferred_to_user_id'],
            'transferred' => $result['transferred'],
        ]);
    }

    /**
     * Approve a pending user account.
     */
    public function approveUser(Request $request, User $user): JsonResponse
    {
        if (! $user->is_approved) {
            $user->update([
                'is_approved' => true,
                'approved_at' => now(),
                'approved_by' => $request->user()?->id,
            ]);
        }

        return response()->json($user->fresh());
    }

    /**
     * Approve all pending user accounts.
     */
    public function approvePendingUsers(Request $request): JsonResponse
    {
        $actorId = $request->user()?->id;
        $approvedCount = 0;

        User::query()
            ->where('is_approved', false)
            ->orderBy('id')
            ->get()
            ->each(function (User $pendingUser) use (&$approvedCount, $actorId): void {
                $pendingUser->update([
                    'is_approved' => true,
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                ]);

                $approvedCount++;
            });

        return response()->json([
            'approved_count' => $approvedCount,
        ]);
    }

    /**
     * Return resources the selected user can share.
     */
    public function sharableResources(): JsonResponse
    {
        $calendars = Calendar::query()
            ->with('owner:id,name,email')
            ->where('is_sharable', true)
            ->orderBy('display_name')
            ->get();

        $addressBooks = AddressBook::query()
            ->with('owner:id,name,email')
            ->where('is_sharable', true)
            ->orderBy('display_name')
            ->get();

        $milestonePurgeVisible = AppSetting::milestonePurgeControlVisible();
        $milestonePurgeAvailable = false;

        if (Schema::hasTable('address_book_contact_milestone_calendars')) {
            if (! $milestonePurgeVisible) {
                $milestonePurgeVisible = AddressBookContactMilestoneCalendar::query()
                    ->exists();

                if ($milestonePurgeVisible) {
                    AppSetting::query()->updateOrCreate(
                        ['key' => 'milestone_purge_control_visible'],
                        ['value' => 'true'],
                    );
                }
            }

            $milestonePurgeAvailable = AddressBookContactMilestoneCalendar::query()
                ->where(function ($query): void {
                    $query->where('enabled', true)
                        ->orWhereNotNull('calendar_id');
                })
                ->exists();
        }

        return response()->json([
            'calendars' => $calendars,
            'address_books' => $addressBooks,
            'milestone_purge_visible' => $milestonePurgeVisible,
            'milestone_purge_available' => $milestonePurgeAvailable,
        ]);
    }

    /**
     * Enable or disable public registration.
     */
    public function setRegistrationSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->registrationSettings->setPublicRegistrationEnabled(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isPublicRegistrationEnabled(),
            'require_approval' => $this->registrationSettings->isPublicRegistrationApprovalRequired(),
        ]);
    }

    /**
     * Enable or disable registration approval requirements.
     */
    public function setRegistrationApprovalSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->registrationSettings->setPublicRegistrationApprovalRequired(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isPublicRegistrationApprovalRequired(),
        ]);
    }

    /**
     * Enable or disable owner-managed sharing.
     */
    public function setOwnerShareManagementSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->registrationSettings->setOwnerShareManagementEnabled(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isOwnerShareManagementEnabled(),
        ]);
    }

    /**
     * Enable or disable DAV compatibility mode.
     */
    public function setDavCompatibilityModeSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->registrationSettings->setDavCompatibilityModeEnabled(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isDavCompatibilityModeEnabled(),
        ]);
    }

    /**
     * Enable or disable contact management features.
     */
    public function setContactManagementSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if (
            (bool) $data['enabled']
            && (
                ! Schema::hasTable('contacts')
                || ! Schema::hasTable('contact_address_book_assignments')
            )
        ) {
            abort(422, __('admin.contact_management_schema_missing'));
        }

        $this->registrationSettings->setContactManagementEnabled(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isContactManagementEnabled(),
        ]);
    }

    /**
     * Enable or disable contact change moderation.
     */
    public function setContactChangeModerationSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];

        if (
            $enabled
            && ! Schema::hasTable('contact_change_requests')
        ) {
            abort(422, __('admin.contact_change_moderation_schema_missing'));
        }

        if (! $enabled && Schema::hasTable('contact_change_requests')) {
            $unresolvedCount = ContactChangeRequest::query()
                ->whereIn('status', [
                    ContactChangeStatus::Pending->value,
                    ContactChangeStatus::Approved->value,
                    ContactChangeStatus::ManualMergeNeeded->value,
                ])
                ->count();

            if ($unresolvedCount > 0) {
                abort(
                    422,
                    __('admin.resolve_or_deny_unresolved_queue_before_disabling', ['count' => $unresolvedCount])
                );
            }
        }

        $this->registrationSettings->setContactChangeModerationEnabled(
            enabled: $enabled,
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isContactChangeModerationEnabled(),
        ]);
    }

    /**
     * Enable or disable private working set features.
     */
    public function setPrivateWorkingSetSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->registrationSettings->setPrivateWorkingSetEnabled(
            enabled: (bool) $data['enabled'],
            actor: $request->user()
        );

        return response()->json([
            'enabled' => $this->registrationSettings->isPrivateWorkingSetEnabled(),
        ]);
    }

    /**
     * Update two-factor enforcement settings.
     */
    public function setTwoFactorEnforcementSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->twoFactorSettings->setEnforced(
            enabled: (bool) $data['enabled'],
            actor: $request->user(),
        );

        return response()->json([
            'enabled' => $this->twoFactorSettings->isEnforced(),
            'grace_period_days' => $this->twoFactorSettings->gracePeriodDays(),
        ]);
    }

    /**
     * Clear two-factor enrollment and backup codes for a user.
     */
    public function resetUserTwoFactor(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'revoke_app_passwords' => ['sometimes', 'boolean'],
        ]);

        $revokeAppPasswords = (bool) ($data['revoke_app_passwords'] ?? true);

        $this->twoFactor->disable($user, revokeAppPasswords: $revokeAppPasswords);

        return response()->json([
            'ok' => true,
            'two_factor_enabled' => false,
            'app_passwords_revoked' => $revokeAppPasswords,
        ]);
    }

    /**
     * Return contact-change request retention settings.
     */
    public function contactChangeRequestRetentionSetting(): JsonResponse
    {
        return response()->json([
            'days' => $this->registrationSettings->contactChangeRequestRetentionDays(),
        ]);
    }

    /**
     * Update contact-change request retention settings.
     */
    public function setContactChangeRequestRetentionSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $this->registrationSettings->setContactChangeRequestRetentionDays(
            days: (int) $data['days'],
            actor: $request->user(),
        );

        return response()->json([
            'days' => $this->registrationSettings->contactChangeRequestRetentionDays(),
        ]);
    }

    /**
     * Return milestone calendar generation-year settings.
     */
    public function milestoneGenerationYearsSetting(): JsonResponse
    {
        return response()->json([
            'years' => $this->registrationSettings->milestoneCalendarGenerationYears(),
        ]);
    }

    /**
     * Update milestone calendar generation-year settings.
     */
    public function setMilestoneGenerationYearsSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'years' => ['required', 'integer', 'min:1', 'max:25'],
        ]);

        $this->registrationSettings->setMilestoneCalendarGenerationYears(
            years: (int) $data['years'],
            actor: $request->user(),
        );

        if (Schema::hasTable('address_book_contact_milestone_calendars')) {
            $addressBookIds = AddressBookContactMilestoneCalendar::query()
                ->where('enabled', true)
                ->pluck('address_book_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $this->milestoneCalendarService->syncAddressBooksByIds($addressBookIds);
        }

        return response()->json([
            'years' => $this->registrationSettings->milestoneCalendarGenerationYears(),
        ]);
    }

    /**
     * Purge generated milestone calendars for selected address books.
     */
    public function purgeGeneratedMilestoneCalendars(): JsonResponse
    {
        $summary = $this->milestoneCalendarService->purgeGeneratedCalendarsAndDisableSettings();

        return response()->json($summary);
    }

    /**
     * Return backup configuration and last-run status.
     */
    public function backupSettings(): JsonResponse
    {
        return response()->json($this->backupSettings->current());
    }

    /**
     * Update backup configuration settings.
     */
    public function setBackupSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'local_enabled' => ['required', 'boolean'],
            'local_path' => ['required', 'string', 'max:1024'],
            's3_enabled' => ['required', 'boolean'],
            's3_disk' => ['required', 'string', 'max:255'],
            's3_prefix' => ['nullable', 'string', 'max:1024'],
            'schedule_times' => ['required', 'array', 'min:1'],
            'schedule_times.*' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['required', 'timezone'],
            'weekly_day' => ['required', 'integer', 'min:0', 'max:6'],
            'monthly_day' => ['required', 'integer', 'min:1', 'max:31'],
            'yearly_month' => ['required', 'integer', 'min:1', 'max:12'],
            'yearly_day' => ['required', 'integer', 'min:1', 'max:31'],
            'retention_daily' => ['required', 'integer', 'min:0', 'max:3650'],
            'retention_weekly' => ['required', 'integer', 'min:0', 'max:520'],
            'retention_monthly' => ['required', 'integer', 'min:0', 'max:240'],
            'retention_yearly' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        if ((bool) $data['enabled'] && ! (bool) $data['local_enabled'] && ! (bool) $data['s3_enabled']) {
            abort(422, __('backups.enable_at_least_one_destination'));
        }

        if (
            (int) $data['retention_daily'] === 0
            && (int) $data['retention_weekly'] === 0
            && (int) $data['retention_monthly'] === 0
            && (int) $data['retention_yearly'] === 0
        ) {
            abort(422, __('backups.at_least_one_retention_tier_gt_zero'));
        }

        return response()->json($this->backupSettings->update($data, $request->user()));
    }

    /**
     * Run a backup immediately from the admin panel.
     */
    public function runBackupNow(Request $request): JsonResponse
    {
        try {
            $queued = $this->backupRunDispatchService->start(
                requestedByUserId: (int) $request->user()->id,
                trigger: 'manual-admin',
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'status' => 'failed',
                'reason' => __('backups.backup_failed_reason', ['reason' => $throwable->getMessage()]),
            ], 422);
        }

        return response()->json($queued, 202);
    }

    /**
     * Return current backup-run status.
     */
    public function backupRunStatus(Request $request): JsonResponse
    {
        $operationId = trim((string) $request->query('operation_id', ''));

        return response()->json(
            $this->backupRunDispatchService->status(
                $operationId === '' ? null : $operationId
            )
        );
    }

    /**
     * Restore data from an uploaded backup archive.
     */
    public function restoreBackup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'backup' => ['required', 'file', 'max:102400'],
            'mode' => ['nullable', 'in:merge,replace'],
            'dry_run' => ['nullable', 'boolean'],
            'fallback_owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $archive = $request->file('backup');
        if (! $archive || ! $archive->isValid()) {
            abort(422, __('backups.archive_upload_missing_or_invalid'));
        }

        $archivePath = $archive->getRealPath();
        if (! is_string($archivePath) || $archivePath === '') {
            abort(422, __('backups.unable_to_access_uploaded_archive'));
        }

        $mode = (string) ($data['mode'] ?? 'merge');
        $dryRun = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOLEAN);
        $fallbackOwnerId = array_key_exists('fallback_owner_id', $data)
            && $data['fallback_owner_id'] !== null
            ? (int) $data['fallback_owner_id']
            : (int) $request->user()->id;

        try {
            $queued = $this->backupRestoreDispatchService->start(
                archivePath: $archivePath,
                mode: $mode,
                dryRun: (bool) $dryRun,
                fallbackOwnerId: $fallbackOwnerId,
                requestedByUserId: (int) $request->user()->id,
                trigger: 'manual-admin',
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'status' => 'failed',
                'reason' => __('backups.restore_failed_reason', ['reason' => $throwable->getMessage()]),
            ], 422);
        }

        return response()->json($queued, 202);
    }

    /**
     * Return current backup restore status.
     */
    public function backupRestoreStatus(Request $request): JsonResponse
    {
        $operationId = trim((string) $request->query('operation_id', ''));

        return response()->json(
            $this->backupRestoreDispatchService->status(
                $operationId === '' ? null : $operationId
            )
        );
    }
}
