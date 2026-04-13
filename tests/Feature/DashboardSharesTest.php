<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardSharesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_owned_and_shared_resources_with_permission_labels(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $sharedCalendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Team Calendar',
            'uri' => 'team-calendar',
            'is_sharable' => true,
        ]);

        $sharedAddressBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Team Contacts',
            'uri' => 'team-contacts',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::Calendar,
            'resource_id' => $sharedCalendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $sharedAddressBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Admin,
        ]);

        $response = $this->actingAs($recipient)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('shared.calendars.0.permission', 'read_only');
        $response->assertJsonPath('shared.address_books.0.permission', 'admin');
        $response->assertJsonFragment(['display_name' => 'Team Calendar']);
        $response->assertJsonFragment(['display_name' => 'Team Contacts']);
    }

    public function test_dashboard_includes_editor_permission_for_shared_resources(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $sharedAddressBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Editor Contacts',
            'uri' => 'editor-contacts',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $sharedAddressBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Editor,
        ]);

        $response = $this->actingAs($recipient)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('shared.address_books.0.permission', 'editor');
    }

    public function test_dashboard_redacts_share_management_payload_when_caller_cannot_manage(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(false);

        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $thirdUser = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::Calendar,
            'resource_id' => $calendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::Calendar,
            'resource_id' => $calendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $thirdUser->id,
            'permission' => SharePermission::Editor,
        ]);

        $response = $this->actingAs($owner)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('sharing.owner_share_management_enabled', false);
        $response->assertJsonPath('sharing.can_manage', false);
        $response->assertJsonPath('sharing.targets', []);
        $response->assertJsonPath('sharing.outgoing', []);
    }

    public function test_dashboard_outgoing_shares_include_resource_display_name(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $addressBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Family Contacts',
            'uri' => 'family-contacts',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $addressBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Editor,
        ]);

        $response = $this->actingAs($owner)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('sharing.outgoing.0.resource_type', 'address_book');
        $response->assertJsonPath('sharing.outgoing.0.resource_id', $addressBook->id);
        $response->assertJsonPath('sharing.outgoing.0.resource_display_name', 'Family Contacts');
    }

    public function test_dashboard_share_targets_support_search_and_pagination(): void
    {
        app(RegistrationSettingsService::class)->setOwnerShareManagementEnabled(true);

        $owner = User::factory()->create();
        User::factory()->create([
            'name' => 'Alice Doe',
            'email' => 'alice.doe@example.com',
        ]);
        User::factory()->create([
            'name' => 'Bob Doe',
            'email' => 'bob.doe@example.com',
        ]);
        User::factory()->create([
            'name' => 'Charlie Other',
            'email' => 'charlie.other@example.com',
        ]);

        $response = $this->actingAs($owner)->getJson(
            '/api/dashboard?share_target_search=doe&share_target_per_page=1&share_target_page=1'
        );

        $response->assertOk()
            ->assertJsonPath('sharing.can_manage', true)
            ->assertJsonCount(1, 'sharing.targets')
            ->assertJsonPath('sharing.targets.0.name', 'Alice Doe')
            ->assertJsonPath('sharing.target_pagination.current_page', 1)
            ->assertJsonPath('sharing.target_pagination.per_page', 1)
            ->assertJsonPath('sharing.target_pagination.total', 2)
            ->assertJsonPath('sharing.target_pagination.search', 'doe');
    }

    public function test_deleting_calendar_via_api_removes_related_resource_shares(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
            'is_default' => false,
        ]);

        $share = ResourceShare::query()->create([
            'resource_type' => ShareResourceType::Calendar,
            'resource_id' => $calendar->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Admin,
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/calendars/'.$calendar->id)
            ->assertOk();

        $this->assertDatabaseMissing('calendars', ['id' => $calendar->id]);
        $this->assertDatabaseMissing('resource_shares', ['id' => $share->id]);
    }

    public function test_deleting_calendar_via_api_removes_related_dav_sync_rows(): void
    {
        $owner = User::factory()->create();

        $calendar = Calendar::factory()->create([
            'owner_id' => $owner->id,
            'is_default' => false,
        ]);

        DB::table('dav_resource_sync_states')->insert([
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
            'sync_token' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dav_resource_sync_changes')->insert([
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
            'sync_token' => 4,
            'operation' => 'modified',
            'uri' => 'keep-clean.ics',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('dav_resource_sync_states', [
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
        ]);
        $this->assertDatabaseHas('dav_resource_sync_changes', [
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/calendars/'.$calendar->id)
            ->assertOk();

        $this->assertDatabaseMissing('dav_resource_sync_states', [
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
        ]);
        $this->assertDatabaseMissing('dav_resource_sync_changes', [
            'resource_type' => ShareResourceType::Calendar->value,
            'resource_id' => $calendar->id,
        ]);
    }

    public function test_deleting_address_book_via_api_removes_related_resource_shares(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $addressBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
            'is_default' => false,
        ]);

        $share = ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $addressBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Admin,
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/address-books/'.$addressBook->id)
            ->assertOk();

        $this->assertDatabaseMissing('address_books', ['id' => $addressBook->id]);
        $this->assertDatabaseMissing('resource_shares', ['id' => $share->id]);
    }

    public function test_deleting_address_book_via_api_removes_related_dav_sync_rows(): void
    {
        $owner = User::factory()->create();

        $addressBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_default' => false,
        ]);

        DB::table('dav_resource_sync_states')->insert([
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
            'sync_token' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dav_resource_sync_changes')->insert([
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
            'sync_token' => 6,
            'operation' => 'modified',
            'uri' => 'keep-clean.vcf',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('dav_resource_sync_states', [
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
        ]);
        $this->assertDatabaseHas('dav_resource_sync_changes', [
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/address-books/'.$addressBook->id)
            ->assertOk();

        $this->assertDatabaseMissing('dav_resource_sync_states', [
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
        ]);
        $this->assertDatabaseMissing('dav_resource_sync_changes', [
            'resource_type' => ShareResourceType::AddressBook->value,
            'resource_id' => $addressBook->id,
        ]);
    }
}
