<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\AddressBookPrivateWorkingSetLink;
use App\Models\Card;
use App\Models\ContactChangeRequest;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\Contacts\ContactVCardService;
use App\Services\Dav\Backends\LaravelCardDavBackend;
use App\Services\DavRequestContext;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sabre\DAV\Exception\Forbidden;
use Tests\TestCase;

class PrivateWorkingSetCardDavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RegistrationSettingsService::class)->setContactManagementEnabled(true);
    }

    public function test_enabling_private_working_set_clones_shared_source_and_hides_source_from_discovery(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Family',
            'uri' => 'shared-family',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-person.vcf',
            'uid' => 'source-person-uid',
            'etag' => md5('source-person'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source Person\nN:Person;Source;;;\nUID:source-person-uid\nEMAIL:source@example.test\nEND:VCARD",
        ]);

        $response = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('private_working_set.enabled', true);
        $response->assertJsonPath('private_working_set.hide_shared', true);
        $response->assertJsonPath('private_working_set.selected_source_ids.0', $source->id);

        $privateAddressBookId = (int) $response->json('private_working_set.private_address_book_id');
        $this->assertGreaterThan(0, $privateAddressBookId);
        $this->assertDatabaseHas('address_book_private_working_set_configs', [
            'user_id' => $editor->id,
            'enabled' => true,
            'hide_shared' => true,
            'private_address_book_id' => $privateAddressBookId,
        ]);

        $privateCard = Card::query()
            ->where('address_book_id', $privateAddressBookId)
            ->firstOrFail();
        $this->assertStringContainsString(
            'X-DAVVY-PRIVATE-SOURCE:'.$source->id.'/source-person.vcf',
            $privateCard->data,
        );

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        $backend = app(LaravelCardDavBackend::class);
        $addressBooks = $backend->getAddressBooksForUser($editor->principalUri());
        $addressBookIds = collect($addressBooks)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertNotContains($source->id, $addressBookIds);
        $this->assertContains($privateAddressBookId, $addressBookIds);
    }

    public function test_hidden_shared_source_rejects_carddav_write_for_editor(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Team',
            'uri' => 'shared-team',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'shared-person.vcf',
            'uid' => 'shared-person-uid',
            'etag' => md5('shared-person'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Shared Person\nN:Person;Shared;;;\nUID:shared-person-uid\nEMAIL:shared@example.test\nEND:VCARD",
        ]);

        $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        $backend = app(LaravelCardDavBackend::class);

        $this->expectException(Forbidden::class);
        $backend->updateCard(
            $source->id,
            'shared-person.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Shared Person Updated\nN:Updated;Shared;;;\nUID:shared-person-uid\nEMAIL:shared.updated@example.test\nEND:VCARD",
        );
    }

    public function test_private_override_wins_and_force_pull_rebases_back_to_source(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Relatives',
            'uri' => 'shared-relatives',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'relative.vcf',
            'uid' => 'relative-uid',
            'etag' => md5('relative-initial'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Server Person\nN:Person;Server;;;\nUID:relative-uid\nTEL;TYPE=CELL:+15550000001\nEMAIL:server@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        $backend = app(LaravelCardDavBackend::class);
        $backend->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Server Person\nN:Person;Server;;;\nUID:{$privateCard->uid}\nTEL;TYPE=CELL:+15550000001\nEMAIL:private-local@example.test\nEND:VCARD",
        );

        $linkAfterPrivateEdit = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->firstOrFail();
        $this->assertContains('emails', $linkAfterPrivateEdit->overridden_fields ?? []);

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        $backend->updateCard(
            $source->id,
            'relative.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Server Person Updated\nN:Updated;Server;;;\nUID:relative-uid\nTEL;TYPE=CELL:+15550000099\nEMAIL:server-updated@example.test\nEND:VCARD",
        );

        $privateAfterSourceUpdate = Card::query()->findOrFail($privateCard->id);
        $privateParsed = app(ContactVCardService::class)->parse($privateAfterSourceUpdate->data);
        $privatePayload = is_array($privateParsed) ? ($privateParsed['payload'] ?? []) : [];

        $this->assertSame('private-local@example.test', $privatePayload['emails'][0]['value'] ?? null);
        $this->assertSame('+15550000099', $privatePayload['phones'][0]['value'] ?? null);
        $this->assertSame('Updated', $privatePayload['last_name'] ?? null);

        $this->actingAs($editor)->postJson('/api/address-books/private-working-set/pull', [
            'force_server' => true,
        ])->assertOk();

        $privateAfterForcePull = Card::query()->findOrFail($privateCard->id);
        $privateAfterPullParsed = app(ContactVCardService::class)->parse($privateAfterForcePull->data);
        $privateAfterPullPayload = is_array($privateAfterPullParsed)
            ? ($privateAfterPullParsed['payload'] ?? [])
            : [];

        $this->assertSame('server-updated@example.test', $privateAfterPullPayload['emails'][0]['value'] ?? null);
        $linkAfterForcePull = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->firstOrFail();
        $this->assertSame([], $linkAfterForcePull->overridden_fields ?? []);
    }

    public function test_dashboard_suggests_promotions_and_dismiss_reappears_after_next_change(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Suggestions',
            'uri' => 'shared-suggestions',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'suggestion-source.vcf',
            'uid' => 'suggestion-source-uid',
            'etag' => md5('suggestion-source'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Suggestion Source\nN:Source;Suggestion;;;\nUID:suggestion-source-uid\nEMAIL:suggestion-source@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Suggestion Source\nN:Source;Suggestion;;;\nUID:{$privateCard->uid}\nEMAIL:private-suggestion@example.test\nEND:VCARD",
        );

        $dashboard = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $suggestions = $dashboard->json('private_working_set.suggested_promotions') ?? [];
        $this->assertCount(1, $suggestions);
        $this->assertSame($privateCard->id, (int) ($suggestions[0]['private_card_id'] ?? 0));
        $linkId = (int) ($suggestions[0]['link_id'] ?? 0);
        $this->assertGreaterThan(0, $linkId);

        $this->actingAs($editor)
            ->postJson('/api/address-books/private-working-set/suggestions/'.$linkId.'/dismiss')
            ->assertOk()
            ->assertJsonPath('suggested_promotion_dismissed.dismissed', true);

        $afterDismiss = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $this->assertSame([], $afterDismiss->json('private_working_set.suggested_promotions'));

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Suggestion Source\nN:Source;Suggestion;;;\nUID:{$privateCard->uid}\nEMAIL:private-suggestion-next@example.test\nEND:VCARD",
        );

        $afterNextChange = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $nextSuggestions = $afterNextChange->json('private_working_set.suggested_promotions') ?? [];
        $this->assertCount(1, $nextSuggestions);
        $this->assertSame($privateCard->id, (int) ($nextSuggestions[0]['private_card_id'] ?? 0));
    }

    public function test_dashboard_includes_owned_sharable_sources_by_default(): void
    {
        $owner = User::factory()->create();

        $ownedSharable = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Owned Sharable',
            'uri' => 'owned-sharable',
            'is_sharable' => true,
        ]);
        AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Owned Private',
            'uri' => 'owned-private',
            'is_sharable' => false,
        ]);

        $dashboard = $this->actingAs($owner)->getJson('/api/dashboard')->assertOk();
        $sourceOptions = collect($dashboard->json('private_working_set.source_options') ?? []);

        $ownedOption = $sourceOptions->firstWhere('id', $ownedSharable->id);
        $this->assertIsArray($ownedOption);
        $this->assertSame('owned', $ownedOption['scope'] ?? null);
        $this->assertSame(true, $ownedOption['can_write'] ?? null);
        $this->assertSame('admin', $ownedOption['permission'] ?? null);
    }

    public function test_dashboard_excludes_owned_sources_when_disabled_in_config(): void
    {
        $owner = User::factory()->create();

        $ownedSharable = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Owned Sharable',
            'uri' => 'owned-sharable',
            'is_sharable' => true,
        ]);

        $this->actingAs($owner)->patchJson('/api/address-books/private-working-set', [
            'enabled' => false,
            'hide_shared' => true,
            'include_owned_sharable_sources' => false,
            'require_review_for_self_promotions' => false,
            'source_ids' => [],
        ])->assertOk();

        $dashboard = $this->actingAs($owner)->getJson('/api/dashboard')->assertOk();
        $sourceOptionIds = collect($dashboard->json('private_working_set.source_options') ?? [])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertNotContains($ownedSharable->id, $sourceOptionIds);
    }

    public function test_non_admin_cannot_disable_self_review_policy_when_moderation_enabled(): void
    {
        $user = User::factory()->create();

        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $response = $this->actingAs($user)->patchJson('/api/address-books/private-working-set', [
            'enabled' => false,
            'hide_shared' => true,
            'include_owned_sharable_sources' => true,
            'require_review_for_self_promotions' => false,
            'source_ids' => [],
        ])->assertOk();

        $response->assertJsonPath('private_working_set.require_review_for_self_promotions', true);
        $response->assertJsonPath('private_working_set.can_manage_self_review_policy', false);
        $response->assertJsonPath('private_working_set.effective_require_review_for_self_promotions', true);

        $this->assertDatabaseHas('address_book_private_working_set_configs', [
            'user_id' => $user->id,
            'require_review_for_self_promotions' => true,
        ]);
    }

    public function test_dashboard_does_not_suggest_notes_only_private_overrides(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Notes',
            'uri' => 'shared-notes',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'notes-source.vcf',
            'uid' => 'notes-source-uid',
            'etag' => md5('notes-source'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Notes Source\nN:Source;Notes;;;\nUID:notes-source-uid\nEMAIL:notes-source@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Notes Source\nN:Source;Notes;;;\nUID:{$privateCard->uid}\nEMAIL:notes-source@example.test\nNOTE:Buy birthday gift idea\nEND:VCARD",
        );

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->firstOrFail();
        $this->assertContains('notes', $link->overridden_fields ?? []);

        $dashboard = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $this->assertSame([], $dashboard->json('private_working_set.suggested_promotions'));
    }

    public function test_dashboard_excludes_suggestions_for_read_only_source_shares(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Read Only',
            'uri' => 'shared-read-only',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'readonly-source.vcf',
            'uid' => 'readonly-source-uid',
            'etag' => md5('readonly-source'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Read Only Source\nN:Source;Read Only;;;\nUID:readonly-source-uid\nEMAIL:readonly-source@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($recipient)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($recipient);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Read Only Source\nN:Source;Read Only;;;\nUID:{$privateCard->uid}\nEMAIL:readonly-private@example.test\nEND:VCARD",
        );

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->firstOrFail();
        $this->assertContains('emails', $link->overridden_fields ?? []);

        $dashboard = $this->actingAs($recipient)->getJson('/api/dashboard')->assertOk();
        $this->assertSame([], $dashboard->json('private_working_set.suggested_promotions'));
    }

    public function test_read_only_source_cannot_be_promoted_from_private_working_set(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Read Only Source',
            'uri' => 'read-only-source',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'readonly-promote-source.vcf',
            'uid' => 'readonly-promote-source-uid',
            'etag' => md5('readonly-promote-source'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Read Only Source\nN:Source;Read Only;;;\nUID:readonly-promote-source-uid\nEMAIL:readonly-promote-source@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($recipient)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($recipient);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Read Only Source\nN:Source;Read Only;;;\nUID:{$privateCard->uid}\nEMAIL:readonly-private-promote@example.test\nEND:VCARD",
        );

        $this->actingAs($recipient)->postJson(
            '/api/address-books/private-working-set/promote/'.$privateCard->id,
        )->assertForbidden();
    }

    public function test_dismiss_suggestion_requires_link_owner(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $intruder = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Ownership',
            'uri' => 'shared-ownership',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'ownership-source.vcf',
            'uid' => 'ownership-source-uid',
            'etag' => md5('ownership-source'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Ownership Source\nN:Source;Ownership;;;\nUID:ownership-source-uid\nEMAIL:ownership-source@example.test\nEND:VCARD",
        ]);

        $config = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Ownership Source\nN:Source;Ownership;;;\nUID:{$privateCard->uid}\nEMAIL:ownership-private@example.test\nEND:VCARD",
        );

        $dashboard = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $suggestions = $dashboard->json('private_working_set.suggested_promotions') ?? [];
        $this->assertNotEmpty($suggestions);
        $linkId = (int) ($suggestions[0]['link_id'] ?? 0);
        $this->assertGreaterThan(0, $linkId);

        $this->actingAs($intruder)
            ->postJson('/api/address-books/private-working-set/suggestions/'.$linkId.'/dismiss')
            ->assertForbidden();
    }

    public function test_promote_private_card_queues_review_when_moderation_enabled(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Promotion Source',
            'uri' => 'shared-promotion-source',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        app(LaravelCardDavBackend::class)->createCard(
            $source->id,
            'promote-source.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Promote Source\nN:Source;Promote;;;\nUID:promote-source-uid\nEMAIL:promote-source@example.test\nEND:VCARD",
        );

        $config = $this->actingAs($editor)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($editor);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Promote Source\nN:Source;Promote;;;\nUID:{$privateCard->uid}\nEMAIL:private-promote@example.test\nEND:VCARD",
        );

        $beforePromote = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $beforePromoteSuggestions = $beforePromote->json('private_working_set.suggested_promotions') ?? [];
        $this->assertNotEmpty($beforePromoteSuggestions);

        $promote = $this->actingAs($editor)->postJson(
            '/api/address-books/private-working-set/promote/'.$privateCard->id,
        );

        $promote->assertStatus(202);
        $promote->assertJsonPath('queued', true);
        $this->assertDatabaseHas('contact_change_requests', [
            'requester_id' => $editor->id,
            'operation' => 'update',
            'status' => 'pending',
            'source' => 'carddav',
        ]);

        $sourceCard = Card::query()
            ->where('address_book_id', $source->id)
            ->where('uri', 'promote-source.vcf')
            ->firstOrFail();
        $this->assertStringContainsString('EMAIL:promote-source@example.test', $sourceCard->data);

        $afterPromote = $this->actingAs($editor)->getJson('/api/dashboard')->assertOk();
        $this->assertSame([], $afterPromote->json('private_working_set.suggested_promotions'));

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->firstOrFail();
        $this->assertNotNull($link->dismissed_suggestion_fingerprint);
    }

    public function test_admin_self_promotion_can_queue_and_self_approve(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $source = AddressBook::factory()->create([
            'owner_id' => $admin->id,
            'display_name' => 'Admin Source',
            'uri' => 'admin-source',
            'is_sharable' => true,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($admin);
        app(LaravelCardDavBackend::class)->createCard(
            $source->id,
            'admin-source.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Admin Source\nN:Source;Admin;;;\nUID:admin-source-uid\nEMAIL:admin-source@example.test\nEND:VCARD",
        );

        $config = $this->actingAs($admin)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'include_owned_sharable_sources' => true,
            'require_review_for_self_promotions' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($admin);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Admin Source\nN:Source;Admin;;;\nUID:{$privateCard->uid}\nEMAIL:admin-private@example.test\nEND:VCARD",
        );

        $promote = $this->actingAs($admin)->postJson(
            '/api/address-books/private-working-set/promote/'.$privateCard->id,
        );

        $promote->assertStatus(202);
        $request = ContactChangeRequest::query()
            ->where('requester_id', $admin->id)
            ->where('operation', 'update')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, (int) $request->approval_owner_id);

        $this->actingAs($admin)
            ->patchJson('/api/contact-change-requests/'.$request->id.'/approve')
            ->assertOk();

        $this->assertDatabaseMissing('contact_change_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    public function test_non_admin_self_promotion_routes_review_to_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();

        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Owner Source',
            'uri' => 'owner-source',
            'is_sharable' => true,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        app(LaravelCardDavBackend::class)->createCard(
            $source->id,
            'owner-source.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Owner Source\nN:Source;Owner;;;\nUID:owner-source-uid\nEMAIL:owner-source@example.test\nEND:VCARD",
        );

        $config = $this->actingAs($owner)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'include_owned_sharable_sources' => true,
            'require_review_for_self_promotions' => false,
            'source_ids' => [$source->id],
        ])->assertOk();

        $config->assertJsonPath('private_working_set.require_review_for_self_promotions', true);
        $config->assertJsonPath('private_working_set.can_manage_self_review_policy', false);
        $config->assertJsonPath('private_working_set.effective_require_review_for_self_promotions', true);

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Owner Source\nN:Source;Owner;;;\nUID:{$privateCard->uid}\nEMAIL:owner-private@example.test\nEND:VCARD",
        );

        $promote = $this->actingAs($owner)->postJson(
            '/api/address-books/private-working-set/promote/'.$privateCard->id,
        );

        $promote->assertStatus(202);
        $request = ContactChangeRequest::query()
            ->where('requester_id', $owner->id)
            ->where('operation', 'update')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, (int) $request->approval_owner_id);

        $this->actingAs($owner)
            ->patchJson('/api/contact-change-requests/'.$request->id.'/approve')
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson('/api/contact-change-requests/'.$request->id.'/approve')
            ->assertOk();

        $this->assertDatabaseMissing('contact_change_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    public function test_non_admin_self_promotion_applies_directly_when_moderation_disabled(): void
    {
        $owner = User::factory()->create();

        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(false);

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Owner Direct Apply',
            'uri' => 'owner-direct-apply',
            'is_sharable' => true,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        app(LaravelCardDavBackend::class)->createCard(
            $source->id,
            'owner-direct-apply.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Owner Direct Apply\nN:Apply;Direct;;;\nUID:owner-direct-apply-uid\nEMAIL:owner-direct-apply@example.test\nEND:VCARD",
        );

        $config = $this->actingAs($owner)->patchJson('/api/address-books/private-working-set', [
            'enabled' => true,
            'hide_shared' => true,
            'include_owned_sharable_sources' => true,
            'require_review_for_self_promotions' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $config->assertJsonPath('private_working_set.effective_require_review_for_self_promotions', false);

        $privateAddressBookId = (int) $config->json('private_working_set.private_address_book_id');
        $privateCard = Card::query()->where('address_book_id', $privateAddressBookId)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($owner);
        app(LaravelCardDavBackend::class)->updateCard(
            $privateAddressBookId,
            $privateCard->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Owner Direct Apply\nN:Apply;Direct;;;\nUID:{$privateCard->uid}\nEMAIL:owner-direct-private@example.test\nEND:VCARD",
        );

        $promote = $this->actingAs($owner)->postJson(
            '/api/address-books/private-working-set/promote/'.$privateCard->id,
        );

        $promote->assertOk();
        $promote->assertJsonPath('queued', false);
        $promote->assertJsonPath('applied', true);

        $sourceCard = Card::query()
            ->where('address_book_id', $source->id)
            ->where('uri', 'owner-direct-apply.vcf')
            ->firstOrFail();
        $this->assertStringContainsString('EMAIL:owner-direct-private@example.test', $sourceCard->data);
    }
}
