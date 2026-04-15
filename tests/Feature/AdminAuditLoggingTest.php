<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Calendar;
use App\Models\User;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_setting_toggle_creates_audit_event(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/dav-compatibility-mode', [
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $log = AdminAuditLog::query()
            ->where('action', 'admin.setting.dav_compatibility_mode.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(false, $log->context['before_enabled'] ?? null);
        $this->assertSame(true, $log->context['enabled'] ?? null);
    }

    public function test_admin_backup_actions_create_audit_events(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'enabled' => true,
            'local_enabled' => true,
            'local_path' => storage_path('framework/testing/admin-audit-backups'),
            's3_enabled' => false,
            's3_disk' => 's3',
            's3_prefix' => 'davvy-audit-test',
            'schedule_times' => ['02:30'],
            'timezone' => 'UTC',
            'weekly_day' => 0,
            'monthly_day' => 1,
            'yearly_month' => 1,
            'yearly_day' => 1,
            'retention_daily' => 7,
            'retention_weekly' => 4,
            'retention_monthly' => 12,
            'retention_yearly' => 3,
        ];

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/backups', $payload)
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/api/admin/backups/run')
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $upload = UploadedFile::fake()->createWithContent('restore.zip', 'not-a-zip');
        $this->actingAs($admin)
            ->post('/api/admin/backups/restore', [
                'backup' => $upload,
                'mode' => 'merge',
                'dry_run' => '1',
            ])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'admin.backup.settings.updated',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'admin.backup.run.queued',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'admin.backup.restore.queued',
        ]);
    }

    public function test_admin_share_create_and_delete_are_audited(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(false);

        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $created = $this->actingAs($admin)
            ->postJson('/api/admin/shares', [
                'resource_type' => 'calendar',
                'resource_id' => $calendar->id,
                'shared_with_id' => $recipient->id,
                'permission' => 'admin',
            ])
            ->assertCreated()
            ->json();

        $shareId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $shareId);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/shares/'.$shareId)
            ->assertOk();

        $createLog = AdminAuditLog::query()
            ->where('action', 'admin.share.upserted')
            ->latest('id')
            ->first();
        $deleteLog = AdminAuditLog::query()
            ->where('action', 'admin.share.deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($createLog);
        $this->assertSame('created', $createLog->context['operation'] ?? null);
        $this->assertSame($shareId, $createLog->context['share_id'] ?? null);
        $this->assertNotNull($deleteLog);
        $this->assertSame($shareId, $deleteLog->context['share_id'] ?? null);
    }

    public function test_non_admin_share_change_does_not_create_admin_audit_event(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(true);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/shares', [
                'resource_type' => 'calendar',
                'resource_id' => $calendar->id,
                'shared_with_id' => $recipient->id,
                'permission' => 'read_only',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
