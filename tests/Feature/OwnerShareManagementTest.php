<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Calendar;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerShareManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_owner_can_share_own_resource_when_feature_enabled(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(true);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/shares', [
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'shared_with_id' => $recipient->id,
            'permission' => 'read_only',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('resource_shares', [
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'permission' => 'read_only',
        ]);
    }

    public function test_regular_owner_cannot_share_when_feature_disabled(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(false);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/shares', [
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'shared_with_id' => $recipient->id,
            'permission' => 'read_only',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_manage_shares_even_when_owner_share_management_disabled(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(false);

        $admin = User::factory()->create(['role' => Role::Admin]);
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/shares', [
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'shared_with_id' => $recipient->id,
            'permission' => 'admin',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('resource_shares', [
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'permission' => 'admin',
        ]);
    }

    public function test_owner_can_assign_editor_permission_when_feature_enabled(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(true);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/shares', [
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'shared_with_id' => $recipient->id,
            'permission' => 'editor',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('resource_shares', [
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'permission' => 'editor',
        ]);
    }

    public function test_admin_share_index_supports_search_filters_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create([
            'name' => 'Owner Example',
            'email' => 'owner@example.com',
        ]);
        $alice = User::factory()->create([
            'name' => 'Alice Recipient',
            'email' => 'alice.recipient@example.com',
        ]);
        $bob = User::factory()->create([
            'name' => 'Bob Recipient',
            'email' => 'bob.recipient@example.com',
        ]);

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $alice->id,
            'permission' => 'read_only',
        ]);
        ResourceShare::query()->create([
            'resource_type' => 'calendar',
            'resource_id' => $calendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $bob->id,
            'permission' => 'editor',
        ]);

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/shares?q=alice&permission=read_only&per_page=1&page=1'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shared_with.id', $alice->id)
            ->assertJsonPath('data.0.permission', 'read_only')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('filters.q', 'alice')
            ->assertJsonPath('filters.permission', 'read_only');
    }
}
