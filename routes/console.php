<?php

use App\Enums\Role;
use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Mail\AdminUserInviteMail;
use App\Mail\PublicRegistrationVerificationMail;
use App\Models\AddressBook;
use App\Models\AddressBookContactMilestoneCalendar;
use App\Models\Calendar;
use App\Models\CalendarObject;
use App\Models\Card;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\Backups\BackupRestoreService;
use App\Services\Backups\BackupService;
use App\Services\Contacts\ContactMilestoneCalendarService;
use App\Services\Contacts\ContactPhotoMetricsService;
use App\Services\Contacts\ContactPhotoService;
use App\Services\Dav\Backends\LaravelCalendarBackend;
use App\Services\Dav\Backends\LaravelCardDavBackend;
use App\Services\Dav\DavSyncService;
use App\Services\DavRequestContext;
use App\Services\RegistrationSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Artisan::command('app:about', function (): void {
    $this->comment('Davvy MVP - Laravel + SabreDAV');
});

Artisan::command(
    'app:qa:seed-mobile-review-queue
    {--owner-name=Owner Admin : Name for owner admin account}
    {--owner-email=owner_admin@example.test : Email for owner admin account}
    {--owner-password=OwnerTemp!234 : Password for owner admin account}
    {--editor-name=Editor Mobile : Name for editor account}
    {--editor-email=editor_mobile@example.test : Email for editor account}
    {--editor-password=EditorTemp!234 : Password for editor account}
    {--observer-name=Observer Mobile : Name for observer account}
    {--observer-email=observer_mobile@example.test : Email for observer account}
    {--observer-password=ObserverTemp!234 : Password for observer account}
    {--observer-permission=read_only : Share permission for observer (read_only|editor)}
    {--force : Apply changes without confirmation}',
    function (): int {
        $requiredTables = [
            'users',
            'address_books',
            'calendars',
            'resource_shares',
            'cards',
            'calendar_objects',
            'app_settings',
            'contacts',
            'contact_address_book_assignments',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $this->error(sprintf('Required table "%s" is missing. Run migrations first.', $table));

                return 1;
            }
        }

        $observerPermissionRaw = Str::lower(trim((string) $this->option('observer-permission')));
        if (! in_array($observerPermissionRaw, [SharePermission::ReadOnly->value, SharePermission::Editor->value], true)) {
            $this->error('--observer-permission must be either "read_only" or "editor".');

            return 1;
        }

        $ownerName = trim((string) $this->option('owner-name'));
        $ownerEmail = Str::lower(trim((string) $this->option('owner-email')));
        $ownerPassword = (string) $this->option('owner-password');

        $editorName = trim((string) $this->option('editor-name'));
        $editorEmail = Str::lower(trim((string) $this->option('editor-email')));
        $editorPassword = (string) $this->option('editor-password');

        $observerName = trim((string) $this->option('observer-name'));
        $observerEmail = Str::lower(trim((string) $this->option('observer-email')));
        $observerPassword = (string) $this->option('observer-password');

        if (
            $ownerName === '' || $ownerEmail === '' || $ownerPassword === ''
            || $editorName === '' || $editorEmail === '' || $editorPassword === ''
            || $observerName === '' || $observerEmail === '' || $observerPassword === ''
        ) {
            $this->error('Names, emails, and passwords for owner/editor/observer must not be empty.');

            return 1;
        }

        if (! (bool) $this->option('force')) {
            $this->line('This command will create or update QA fixture users and shared resources:');
            $this->line(sprintf('  owner admin: %s', $ownerEmail));
            $this->line(sprintf('  editor:      %s', $editorEmail));
            $this->line(sprintf('  observer:    %s (%s)', $observerEmail, $observerPermissionRaw));
            $this->line('  address book URI: rq-shared-contacts');
            $this->line('  calendar URI:     rq-shared-calendar');
            $this->line('  contact card URI: rq-test-person.vcf');
            $this->line('  event object URI: rq-calendar-control-event.ics');
            if (! $this->confirm('Proceed with seeding/updating this fixture?', false)) {
                $this->warn('Aborted.');

                return 1;
            }
        }

        $owner = User::query()->firstOrNew(['email' => $ownerEmail]);
        $owner->name = $ownerName;
        $owner->password = $ownerPassword;
        $owner->role = Role::Admin;
        $owner->is_approved = true;
        $owner->approved_at = now();
        $owner->approved_by = null;
        if ($owner->email_verified_at === null) {
            $owner->email_verified_at = now();
        }
        $owner->save();

        $editor = User::query()->firstOrNew(['email' => $editorEmail]);
        $editor->name = $editorName;
        $editor->password = $editorPassword;
        $editor->role = Role::Regular;
        $editor->is_approved = true;
        $editor->approved_at = now();
        $editor->approved_by = $owner->id;
        if ($editor->email_verified_at === null) {
            $editor->email_verified_at = now();
        }
        $editor->save();

        $observer = User::query()->firstOrNew(['email' => $observerEmail]);
        $observer->name = $observerName;
        $observer->password = $observerPassword;
        $observer->role = Role::Regular;
        $observer->is_approved = true;
        $observer->approved_at = now();
        $observer->approved_by = $owner->id;
        if ($observer->email_verified_at === null) {
            $observer->email_verified_at = now();
        }
        $observer->save();

        /** @var RegistrationSettingsService $settings */
        $settings = app(RegistrationSettingsService::class);
        $settings->setOwnerShareManagementEnabled(true, $owner);
        $settings->setContactManagementEnabled(true, $owner);
        $settings->setContactChangeModerationEnabled(true, $owner);

        $addressBook = AddressBook::query()->firstOrCreate(
            [
                'owner_id' => $owner->id,
                'uri' => 'rq-shared-contacts',
            ],
            [
                'display_name' => 'RQ Shared Contacts',
                'description' => 'QA fixture address book for mobile review queue verification.',
                'is_default' => false,
                'is_sharable' => true,
            ],
        );
        $addressBook->update([
            'display_name' => 'RQ Shared Contacts',
            'description' => 'QA fixture address book for mobile review queue verification.',
            'is_sharable' => true,
        ]);

        $calendar = Calendar::query()->firstOrCreate(
            [
                'owner_id' => $owner->id,
                'uri' => 'rq-shared-calendar',
            ],
            [
                'display_name' => 'RQ Shared Calendar',
                'description' => 'QA fixture calendar for mobile review queue verification.',
                'is_default' => false,
                'is_sharable' => true,
            ],
        );
        $calendar->update([
            'display_name' => 'RQ Shared Calendar',
            'description' => 'QA fixture calendar for mobile review queue verification.',
            'is_sharable' => true,
        ]);

        /** @var DavSyncService $syncService */
        $syncService = app(DavSyncService::class);
        $syncService->ensureResource(ShareResourceType::AddressBook, (int) $addressBook->id);
        $syncService->ensureResource(ShareResourceType::Calendar, (int) $calendar->id);

        ResourceShare::query()->updateOrCreate(
            [
                'resource_type' => ShareResourceType::AddressBook,
                'resource_id' => $addressBook->id,
                'shared_with_id' => $editor->id,
            ],
            [
                'owner_id' => $owner->id,
                'permission' => SharePermission::Editor,
            ],
        );
        ResourceShare::query()->updateOrCreate(
            [
                'resource_type' => ShareResourceType::Calendar,
                'resource_id' => $calendar->id,
                'shared_with_id' => $editor->id,
            ],
            [
                'owner_id' => $owner->id,
                'permission' => SharePermission::Editor,
            ],
        );
        ResourceShare::query()->updateOrCreate(
            [
                'resource_type' => ShareResourceType::AddressBook,
                'resource_id' => $addressBook->id,
                'shared_with_id' => $observer->id,
            ],
            [
                'owner_id' => $owner->id,
                'permission' => $observerPermissionRaw,
            ],
        );
        ResourceShare::query()->updateOrCreate(
            [
                'resource_type' => ShareResourceType::Calendar,
                'resource_id' => $calendar->id,
                'shared_with_id' => $observer->id,
            ],
            [
                'owner_id' => $owner->id,
                'permission' => $observerPermissionRaw,
            ],
        );

        /** @var DavRequestContext $davContext */
        $davContext = app(DavRequestContext::class);
        $previousActor = $davContext->getAuthenticatedUser();
        $previousUserAgent = $davContext->getUserAgent();
        $davContext->setAuthenticatedUser($owner);
        $davContext->setUserAgent('davvy-qa-seeder');

        try {
            $contactCardUri = 'rq-test-person.vcf';
            $contactUid = 'rq-test-person-uid';
            $cardData = "BEGIN:VCARD\n"
                ."VERSION:4.0\n"
                ."FN:RQ Test Person\n"
                ."N:Person;RQ;Test;;\n"
                ."UID:{$contactUid}\n"
                ."TEL;TYPE=CELL:+13175550111\n"
                ."EMAIL;TYPE=INTERNET:rq-test-person@example.test\n"
                ."END:VCARD";

            /** @var LaravelCardDavBackend $cardBackend */
            $cardBackend = app(LaravelCardDavBackend::class);
            $cardByUri = Card::query()
                ->where('address_book_id', $addressBook->id)
                ->where('uri', $contactCardUri)
                ->first();
            $cardByUid = Card::query()
                ->where('address_book_id', $addressBook->id)
                ->where('uid', $contactUid)
                ->first();

            if ($cardByUri) {
                $cardBackend->updateCard($addressBook->id, $cardByUri->uri, $cardData);
            } elseif ($cardByUid) {
                $cardBackend->updateCard($addressBook->id, $cardByUid->uri, $cardData);
            } else {
                $cardBackend->createCard($addressBook->id, $contactCardUri, $cardData);
            }

            $eventObjectUri = 'rq-calendar-control-event.ics';
            $eventUid = 'rq-calendar-control-event-uid';
            $dtStamp = now('UTC')->format('Ymd\\THis\\Z');
            $dtStart = now('UTC')->addDay()->startOfDay()->addHours(15);
            $dtEnd = (clone $dtStart)->addHour();
            $calendarData = "BEGIN:VCALENDAR\n"
                ."VERSION:2.0\n"
                ."PRODID:-//Davvy//Mobile QA Fixture//EN\n"
                ."BEGIN:VEVENT\n"
                ."UID:{$eventUid}\n"
                ."DTSTAMP:{$dtStamp}\n"
                ."DTSTART:".$dtStart->format('Ymd\\THis\\Z')."\n"
                ."DTEND:".$dtEnd->format('Ymd\\THis\\Z')."\n"
                ."SUMMARY:RQ Calendar Control Event\n"
                ."END:VEVENT\n"
                ."END:VCALENDAR";

            /** @var LaravelCalendarBackend $calendarBackend */
            $calendarBackend = app(LaravelCalendarBackend::class);
            $eventByUri = CalendarObject::query()
                ->where('calendar_id', $calendar->id)
                ->where('uri', $eventObjectUri)
                ->first();
            $eventByUid = CalendarObject::query()
                ->where('calendar_id', $calendar->id)
                ->where('uid', $eventUid)
                ->first();

            if ($eventByUri) {
                $calendarBackend->updateCalendarObject($calendar->id, $eventByUri->uri, $calendarData);
            } elseif ($eventByUid) {
                $calendarBackend->updateCalendarObject($calendar->id, $eventByUid->uri, $calendarData);
            } else {
                $calendarBackend->createCalendarObject($calendar->id, $eventObjectUri, $calendarData);
            }
        } finally {
            if ($previousActor) {
                $davContext->setAuthenticatedUser($previousActor);
            } else {
                $davContext->clear();
            }
            $davContext->setUserAgent($previousUserAgent);
        }

        $this->newLine();
        $this->info('Mobile sync + review queue QA fixture is ready.');
        $this->line('Configured settings: owner sharing ON, contact management ON, review queue moderation ON.');
        $this->line('');
        $this->line('Users:');
        $this->line(sprintf('  owner_admin    -> %s', $owner->email));
        $this->line(sprintf('  editor_mobile  -> %s', $editor->email));
        $this->line(sprintf('  observer_mobile-> %s (%s)', $observer->email, $observerPermissionRaw));
        $this->line('');
        $this->line('Resources:');
        $this->line(sprintf('  Address Book: %s (%s)', $addressBook->display_name, $addressBook->uri));
        $this->line(sprintf('  Calendar:     %s (%s)', $calendar->display_name, $calendar->uri));
        $this->line('  Seed Contact Card URI: rq-test-person.vcf');
        $this->line('  Seed Event URI:        rq-calendar-control-event.ics');
        $this->line('');
        $this->line('Next: open docs/mobile-sync-review-queue-test-script.md and run the test cases on devices.');

        return 0;
    },
)->purpose('Seed or refresh a mobile iOS/Android QA fixture for review queue and sync verification');

Artisan::command(
    'app:user:approve
    {identifier : User email address or numeric user ID}
    {--approve : Mark the account as approved}
    {--verify-email : Mark the account email as verified}
    {--force : Apply changes without confirmation}',
    function (): int {
        $identifier = trim((string) $this->argument('identifier'));
        $approve = (bool) $this->option('approve');
        $verifyEmail = (bool) $this->option('verify-email');
        $force = (bool) $this->option('force');

        if (! $approve && ! $verifyEmail) {
            $approve = true;
            $verifyEmail = true;
        }

        if ($identifier === '') {
            $this->error('Identifier cannot be empty.');

            return 1;
        }

        $user = preg_match('/^\d+$/', $identifier) === 1
            ? User::query()->whereKey((int) $identifier)->first()
            : User::query()->where('email', Str::lower($identifier))->first();

        if (! $user) {
            $this->error('No user found for identifier: '.$identifier);

            return 1;
        }

        $actions = [];
        if ($approve) {
            $actions[] = 'approve';
        }
        if ($verifyEmail) {
            $actions[] = 'verify-email';
        }

        $this->line(sprintf(
            'Target user #%d %s (%s)',
            (int) $user->id,
            (string) $user->email,
            implode(', ', $actions),
        ));

        if (! $force) {
            $confirmed = $this->confirm('Apply these account state updates?', false);
            if (! $confirmed) {
                $this->warn('Aborted.');

                return 1;
            }
        }

        $changed = false;

        if ($approve && ! $user->is_approved) {
            $user->is_approved = true;
            $user->approved_at = now();
            $user->approved_by = null;
            $changed = true;
        }

        if ($verifyEmail && $user->email_verified_at === null) {
            $user->email_verified_at = now();
            $changed = true;
        }

        if ($changed) {
            $user->save();
            $user->refresh();
            $this->info('User updated successfully.');
        } else {
            $this->line('No changes were needed.');
        }

        $this->line('Current state:');
        $this->line('  is_approved='.($user->is_approved ? 'true' : 'false'));
        $this->line('  approved_at='.($user->approved_at?->toISOString() ?? 'null'));
        $this->line('  email_verified_at='.($user->email_verified_at?->toISOString() ?? 'null'));

        return 0;
    },
)->purpose('Approve and/or verify a user account from CLI');

Artisan::command(
    'app:user:unapprove
    {identifier : User email address or numeric user ID}
    {--unverify-email : Clear the account email verification timestamp}
    {--force : Apply changes without confirmation}',
    function (): int {
        $identifier = trim((string) $this->argument('identifier'));
        $unverifyEmail = (bool) $this->option('unverify-email');
        $force = (bool) $this->option('force');

        if ($identifier === '') {
            $this->error('Identifier cannot be empty.');

            return 1;
        }

        $user = preg_match('/^\d+$/', $identifier) === 1
            ? User::query()->whereKey((int) $identifier)->first()
            : User::query()->where('email', Str::lower($identifier))->first();

        if (! $user) {
            $this->error('No user found for identifier: '.$identifier);

            return 1;
        }

        $actions = ['unapprove'];
        if ($unverifyEmail) {
            $actions[] = 'unverify-email';
        }

        $this->line(sprintf(
            'Target user #%d %s (%s)',
            (int) $user->id,
            (string) $user->email,
            implode(', ', $actions),
        ));

        if (! $force) {
            $confirmed = $this->confirm('Apply these account state updates?', false);
            if (! $confirmed) {
                $this->warn('Aborted.');

                return 1;
            }
        }

        $changed = false;

        if ($user->is_approved || $user->approved_at !== null || $user->approved_by !== null) {
            $user->is_approved = false;
            $user->approved_at = null;
            $user->approved_by = null;
            $changed = true;
        }

        if ($unverifyEmail && $user->email_verified_at !== null) {
            $user->email_verified_at = null;
            $changed = true;
        }

        if ($changed) {
            $user->save();
            $user->refresh();
            $this->info('User updated successfully.');
        } else {
            $this->line('No changes were needed.');
        }

        $this->line('Current state:');
        $this->line('  is_approved='.($user->is_approved ? 'true' : 'false'));
        $this->line('  approved_at='.($user->approved_at?->toISOString() ?? 'null'));
        $this->line('  email_verified_at='.($user->email_verified_at?->toISOString() ?? 'null'));

        return 0;
    },
)->purpose('Revoke user approval and optionally clear email verification from CLI');

Artisan::command('app:mail:preview-onboarding {--output= : Output directory for preview files}', function (): int {
    $outputDirectory = trim((string) $this->option('output'));
    if ($outputDirectory === '') {
        $outputDirectory = storage_path('app/mail-previews');
    }

    File::ensureDirectoryExists($outputDirectory);

    $previewUser = new User([
        'name' => 'Preview User',
        'email' => 'preview@example.com',
    ]);

    $inviteExpiresAt = now()->addHours(max(1, (int) config('onboarding.invite_expires_hours', 72)));
    $verifyExpiresAt = now()->addHours(max(1, (int) config('onboarding.verification_expires_hours', 24)));
    $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
    $inviteUrl = $baseUrl.'/invite?token='.str_repeat('i', 64);
    $verifyUrl = $baseUrl.'/verify-email?token='.str_repeat('v', 64);

    $inviteMailable = new AdminUserInviteMail($previewUser, $inviteUrl, $inviteExpiresAt);
    $verifyMailable = new PublicRegistrationVerificationMail($previewUser, $verifyUrl, $verifyExpiresAt);

    $files = [
        'admin-invite.html' => $inviteMailable->render(),
        'admin-invite.txt' => view('emails.auth.admin-invite-text', [
            'user' => $previewUser,
            'inviteUrl' => $inviteUrl,
            'expiresAt' => $inviteExpiresAt,
        ])->render(),
        'verify-email.html' => $verifyMailable->render(),
        'verify-email.txt' => view('emails.auth.verify-email-text', [
            'user' => $previewUser,
            'verificationUrl' => $verifyUrl,
            'expiresAt' => $verifyExpiresAt,
        ])->render(),
    ];

    foreach ($files as $fileName => $contents) {
        $path = $outputDirectory.DIRECTORY_SEPARATOR.$fileName;
        file_put_contents($path, $contents);
        $this->line("Wrote: {$path}");
    }

    $this->newLine();
    $this->info('Email previews generated successfully.');
    $this->line('Tip: open the .html files in your browser and .txt files in your editor.');

    return 0;
})->purpose('Generate local preview files for onboarding emails without sending mail');

Artisan::command('app:preflight', function (): int {
    $appEnv = (string) config('app.env', 'production');
    $appUrl = trim((string) config('app.url', ''));
    $appKey = trim((string) config('app.key', ''));
    $dbConnection = (string) config('database.default', '');

    $runDbSeed = filter_var(env('RUN_DB_SEED', false), FILTER_VALIDATE_BOOL);
    $runScheduler = filter_var(env('RUN_SCHEDULER', true), FILTER_VALIDATE_BOOL);
    $secureCookieEnabled = filter_var(env('SESSION_SECURE_COOKIE', false), FILTER_VALIDATE_BOOL);
    $defaultAdminEmail = trim((string) env('DEFAULT_ADMIN_EMAIL', ''));
    $defaultAdminPassword = (string) env('DEFAULT_ADMIN_PASSWORD', '');
    $backupsEnabled = (bool) config('services.backups.enabled', false);
    $backupLocalEnabled = (bool) config('services.backups.local_enabled', true);
    $backupS3Enabled = (bool) config('services.backups.s3_enabled', false);
    $backupScheduleTimes = collect(explode(',', (string) config('services.backups.schedule_times', '')))
        ->map(fn (string $value): string => trim($value))
        ->filter(fn (string $value): bool => $value !== '')
        ->values()
        ->all();
    $backupTimezone = trim((string) config('services.backups.timezone', config('app.timezone', 'UTC')));
    $backupS3Disk = trim((string) config('services.backups.s3_disk', 's3'));
    $corsAllowedOrigins = array_values(array_filter(
        (array) config('cors.allowed_origins', []),
        fn (mixed $origin): bool => is_string($origin) && trim($origin) !== ''
    ));
    $corsSupportsCredentials = (bool) config('cors.supports_credentials', false);

    $errors = [];
    $warnings = [];

    if ($appKey === '') {
        $errors[] = 'APP_KEY is missing.';
    }

    if ($appUrl === '') {
        $errors[] = 'APP_URL is missing.';
    }

    if ($appEnv === 'production') {
        if ((bool) config('app.debug', false)) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }

        if ($appUrl !== '' && ! str_starts_with(strtolower($appUrl), 'https://')) {
            $errors[] = 'APP_URL must use HTTPS in production.';
        }

        if (! $secureCookieEnabled) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production.';
        }

        if ($dbConnection === 'sqlite') {
            $errors[] = 'DB_CONNECTION=sqlite is not recommended for production.';
        }

        if ($corsSupportsCredentials && in_array('*', $corsAllowedOrigins, true)) {
            $errors[] = 'CORS_ALLOWED_ORIGINS must not include "*" when CORS_SUPPORTS_CREDENTIALS=true.';
        }

        if ($runDbSeed) {
            if ($defaultAdminEmail === '' || $defaultAdminPassword === '') {
                $errors[] = 'RUN_DB_SEED=true requires DEFAULT_ADMIN_EMAIL and DEFAULT_ADMIN_PASSWORD.';
            }

            if ($defaultAdminPassword === 'ChangeMe123!') {
                $errors[] = 'DEFAULT_ADMIN_PASSWORD must not use the insecure default value in production.';
            }

            if ($defaultAdminPassword !== '' && mb_strlen($defaultAdminPassword) < 12) {
                $warnings[] = 'DEFAULT_ADMIN_PASSWORD is shorter than 12 characters.';
            }
        }

        if ($backupsEnabled) {
            if (! $backupLocalEnabled && ! $backupS3Enabled) {
                $errors[] = 'ENABLE_AUTOMATED_BACKUPS=true requires BACKUPS_LOCAL_ENABLED=true or BACKUPS_S3_ENABLED=true.';
            }

            if ($backupScheduleTimes === []) {
                $errors[] = 'BACKUPS_SCHEDULE_TIMES must include at least one HH:MM value when backups are enabled.';
            }

            foreach ($backupScheduleTimes as $time) {
                if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    $errors[] = sprintf('BACKUPS_SCHEDULE_TIMES contains invalid value "%s" (expected HH:MM).', $time);
                }
            }

            if ($backupTimezone !== '' && ! in_array($backupTimezone, timezone_identifiers_list(), true)) {
                $errors[] = 'BACKUPS_TIMEZONE must be a valid IANA timezone identifier.';
            }

            if ($backupS3Enabled && $backupS3Disk === '') {
                $errors[] = 'BACKUPS_S3_DISK cannot be empty when BACKUPS_S3_ENABLED=true.';
            }

            if (! $runScheduler) {
                $warnings[] = 'ENABLE_AUTOMATED_BACKUPS=true while RUN_SCHEDULER=false. Use an external scheduler to run "php artisan schedule:run" every minute.';
            }
        }
    }

    if ($warnings !== []) {
        foreach ($warnings as $warning) {
            $this->warn('Warning: '.$warning);
        }
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error('Error: '.$error);
        }

        return 1;
    }

    $this->info('Preflight checks passed.');

    return 0;
})->purpose('Validate runtime security and deployment configuration');

Artisan::command('app:backup {--force : Run immediately, ignoring enabled flag and schedule window}', function (): int {
    /** @var BackupService $backupService */
    $backupService = app(BackupService::class);
    $force = (bool) $this->option('force');
    $trigger = $force ? 'manual-cli' : 'scheduled';

    $result = $backupService->run(force: $force, trigger: $trigger);

    if ($result['status'] === 'success') {
        $this->info($result['reason']);

        return 0;
    }

    if ($result['status'] === 'skipped') {
        $this->line('Skipped: '.$result['reason']);

        return 0;
    }

    $this->error($result['reason']);

    return 1;
})->purpose('Run automated data backups with retention (local and optional S3)');

Artisan::command('app:milestones:sync', function (): int {
    if (! Schema::hasTable('address_book_contact_milestone_calendars')) {
        $this->line('Skipped: milestone settings table not found.');

        return 0;
    }

    $addressBookIds = AddressBookContactMilestoneCalendar::query()
        ->where('enabled', true)
        ->pluck('address_book_id')
        ->map(fn (mixed $id): int => (int) $id)
        ->filter(fn (int $id): bool => $id > 0)
        ->unique()
        ->values()
        ->all();

    if ($addressBookIds === []) {
        $this->line('Skipped: no enabled milestone calendars found.');

        return 0;
    }

    /** @var ContactMilestoneCalendarService $milestoneCalendarService */
    $milestoneCalendarService = app(ContactMilestoneCalendarService::class);
    $milestoneCalendarService->syncAddressBooksByIds($addressBookIds);

    $this->info(sprintf(
        'Synchronized milestone calendars for %d address book(s).',
        count($addressBookIds),
    ));

    return 0;
})->purpose('Re-sync enabled milestone calendars to roll forward upcoming horizon events');

Artisan::command('app:contacts:photos:prune', function (): int {
    if (! Schema::hasTable('contact_photo_uploads')) {
        $this->line('Skipped: contact photo uploads table not found.');

        return 0;
    }

    /** @var ContactPhotoService $photoService */
    $photoService = app(ContactPhotoService::class);
    $expiredDeleted = $photoService->pruneExpiredStagedUploads();
    $orphanedDeleted = $photoService->pruneOrphanedFinalPhotos();

    $this->info(sprintf(
        'Pruned %d expired staged upload(s) and %d orphaned final photo(s).',
        $expiredDeleted,
        $orphanedDeleted,
    ));

    return 0;
})->purpose('Prune expired staged uploads and orphaned managed contact photos');

Artisan::command('app:contacts:photos:metrics-summary', function (): int {
    if (! Schema::hasTable('contacts') || ! Schema::hasTable('cards')) {
        $this->line('Skipped: contacts/cards tables not found.');

        return 0;
    }

    /** @var ContactPhotoMetricsService $metricsService */
    $metricsService = app(ContactPhotoMetricsService::class);
    $summary = $metricsService->summarizeCurrentFootprint();
    Log::info('contact_photo_metric_summary', $summary);

    $cardsP95 = $summary['cards_data_bytes']['p95'] ?? null;
    $photoCardsP95 = $summary['cards_with_embedded_photo_bytes']['p95'] ?? null;

    $this->info(sprintf(
        'Logged contact photo summary: %d/%d contacts with photos; cards p95=%s bytes; photo-cards p95=%s bytes.',
        (int) ($summary['contacts_with_photo'] ?? 0),
        (int) ($summary['contacts_total'] ?? 0),
        $cardsP95 === null ? 'n/a' : (string) $cardsP95,
        $photoCardsP95 === null ? 'n/a' : (string) $photoCardsP95,
    ));

    return 0;
})->purpose('Log managed contact photo and cards.data size distributions');

Artisan::command(
    'app:backup:restore
    {archive : Path to backup ZIP archive}
    {--mode=merge : Restore mode: merge or replace}
    {--dry-run : Validate and preview restore operations without writing changes}
    {--fallback-owner-id= : Assign unresolved backup owner IDs to this user ID}',
    function (): int {
        /** @var BackupRestoreService $backupRestoreService */
        $backupRestoreService = app(BackupRestoreService::class);

        $archivePath = (string) $this->argument('archive');
        $mode = trim((string) $this->option('mode'));
        $dryRun = (bool) $this->option('dry-run');
        $fallbackOwnerInput = $this->option('fallback-owner-id');
        $fallbackOwnerId = null;

        if ($fallbackOwnerInput !== null && $fallbackOwnerInput !== '') {
            if (preg_match('/^\d+$/', (string) $fallbackOwnerInput) !== 1) {
                $this->error('--fallback-owner-id must be a numeric user ID.');

                return 1;
            }

            $fallbackOwnerId = (int) $fallbackOwnerInput;
        }

        try {
            $result = $backupRestoreService->restoreFromArchive(
                archivePath: $archivePath,
                mode: $mode,
                dryRun: $dryRun,
                fallbackOwnerId: $fallbackOwnerId,
                trigger: 'manual-cli',
            );
        } catch (Throwable $throwable) {
            $this->error('Restore failed: '.$throwable->getMessage());

            return 1;
        }

        foreach (($result['warnings'] ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $this->warn('Warning: '.$warning);
            }
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $this->info((string) ($result['reason'] ?? 'Restore complete.'));
        $this->line(sprintf(
            'Calendars created/updated: %d/%d',
            (int) ($summary['calendars_created'] ?? 0),
            (int) ($summary['calendars_updated'] ?? 0),
        ));
        $this->line(sprintf(
            'Address books created/updated: %d/%d',
            (int) ($summary['address_books_created'] ?? 0),
            (int) ($summary['address_books_updated'] ?? 0),
        ));
        $this->line(sprintf(
            'Objects created/updated: %d/%d',
            (int) (($summary['calendar_objects_created'] ?? 0) + ($summary['cards_created'] ?? 0)),
            (int) (($summary['calendar_objects_updated'] ?? 0) + ($summary['cards_updated'] ?? 0)),
        ));

        return 0;
    },
)->purpose('Restore calendars/address books from a backup ZIP archive');

Schedule::command('app:backup')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('app:contacts:photos:prune')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('app:contacts:photos:metrics-summary')
    ->dailyAt('00:30')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('app:milestones:sync')
    ->dailyAt('00:15')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();
