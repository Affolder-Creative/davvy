<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\AddressBookPrivateWorkingSetLink;
use App\Models\Card;
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
}
