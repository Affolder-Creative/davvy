<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\AddressBookMirrorConfig;
use App\Models\AddressBookMirrorLink;
use App\Models\Card;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\Dav\Backends\LaravelCardDavBackend;
use App\Services\DavRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sabre\DAV\Exception\Forbidden;
use Tests\TestCase;

class AppleCompatAddressBookMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_apple_compat_target_and_owned_shared_source_options(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $ownedSource = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Household',
            'uri' => 'household',
        ]);

        $sharedSource = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Shared Team',
            'uri' => 'shared-team',
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $sharedSource->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $user->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('apple_compat.enabled', false);
        $response->assertJsonPath('apple_compat.target_address_book_uri', 'contacts');

        $payload = $response->json();
        $sourceIds = collect($payload['apple_compat']['source_options'])->pluck('id')->all();

        $this->assertContains($ownedSource->id, $sourceIds);
        $this->assertContains($sharedSource->id, $sourceIds);
        $this->assertNotContains($this->defaultContactsBookFor($user)->id, $sourceIds);
    }

    public function test_enabling_apple_compat_mirrors_selected_source_cards_into_contacts(): void
    {
        $user = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Family',
            'uri' => 'family',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-contact.vcf',
            'uid' => 'source-contact-uid',
            'etag' => md5('source-contact'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source Person\nUID:source-contact-uid\nEMAIL:source@example.test\nEND:VCARD",
        ]);

        $response = $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('apple_compat.enabled', true);
        $response->assertJsonPath('apple_compat.selected_source_ids.0', $source->id);

        $target = $this->defaultContactsBookFor($user);
        $mirroredCards = Card::query()->where('address_book_id', $target->id)->get();

        $this->assertCount(1, $mirroredCards);
        $this->assertStringContainsString('FN:Source Person', $mirroredCards->first()->data);
        $this->assertStringContainsString('X-DAVVY-MIRROR-SOURCE:', $mirroredCards->first()->data);

        $this->assertDatabaseHas('address_book_mirror_configs', [
            'user_id' => $user->id,
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('address_book_mirror_links', [
            'user_id' => $user->id,
            'source_address_book_id' => $source->id,
            'source_card_uri' => 'source-contact.vcf',
            'mirrored_address_book_id' => $target->id,
        ]);
    }

    public function test_enabling_apple_compat_adds_photo_type_to_mirrored_cards_when_only_mediatype_exists(): void
    {
        $user = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Family',
            'uri' => 'family-photo',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-photo.vcf',
            'uid' => 'source-photo-uid',
            'etag' => md5('source-photo'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source Photo\nUID:source-photo-uid\nPHOTO;ENCODING=b;MEDIATYPE=image/jpeg:AA==\nEND:VCARD",
        ]);

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($user);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/PHOTO;[^\n]*TYPE=JPEG[^\n]*:/', (string) $mirrored->data);
        $this->assertMatchesRegularExpression('/PHOTO;[^\n]*MEDIATYPE=image\\/jpeg[^\n]*:/i', (string) $mirrored->data);
    }

    public function test_enabling_apple_compat_adds_photo_type_to_data_uri_photo_without_explicit_type(): void
    {
        $user = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Family',
            'uri' => 'family-data-uri-photo',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-photo-data-uri.vcf',
            'uid' => 'source-photo-data-uri-uid',
            'etag' => md5('source-photo-data-uri'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source Photo Data URI\nUID:source-photo-data-uri-uid\nPHOTO:data:image/png;base64,AA==\nEND:VCARD",
        ]);

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($user);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/PHOTO;[^\n]*TYPE=PNG[^\n]*:/', (string) $mirrored->data);
    }

    public function test_source_card_create_update_delete_propagates_to_contacts_when_apple_compat_enabled(): void
    {
        $user = User::factory()->create();
        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Relatives',
            'uri' => 'relatives',
        ]);

        $config = AddressBookMirrorConfig::query()->create([
            'user_id' => $user->id,
            'enabled' => true,
        ]);
        $config->sources()->create([
            'source_address_book_id' => $source->id,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);
        $backend = app(LaravelCardDavBackend::class);

        $backend->createCard(
            $source->id,
            'relative.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Relative One\nUID:relative-uid\nEMAIL:relative.one@example.test\nEND:VCARD"
        );

        $target = $this->defaultContactsBookFor($user);
        $mirrored = Card::query()->where('address_book_id', $target->id)->first();
        $this->assertNotNull($mirrored);
        $this->assertStringContainsString('FN:Relative One', $mirrored->data);

        $backend->updateCard(
            $source->id,
            'relative.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Relative Updated\nUID:relative-uid\nEMAIL:relative.one@example.test\nEND:VCARD"
        );

        $mirroredAfterUpdate = Card::query()->where('address_book_id', $target->id)->first();
        $this->assertNotNull($mirroredAfterUpdate);
        $this->assertStringContainsString('FN:Relative Updated', $mirroredAfterUpdate->data);

        $backend->deleteCard($source->id, 'relative.vcf');

        $this->assertDatabaseCount('address_book_mirror_links', 0);
        $this->assertSame(0, Card::query()->where('address_book_id', $target->id)->count());
    }

    public function test_disabling_apple_compat_removes_existing_mirrored_cards(): void
    {
        $user = User::factory()->create();
        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Neighbors',
            'uri' => 'neighbors',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'neighbor.vcf',
            'uid' => 'neighbor-uid',
            'etag' => md5('neighbor'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Neighbor\nUID:neighbor-uid\nEMAIL:neighbor@example.test\nEND:VCARD",
        ]);

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($user);
        $this->assertGreaterThan(0, Card::query()->where('address_book_id', $target->id)->count());
        $this->assertGreaterThan(0, AddressBookMirrorLink::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => false,
            'source_ids' => [$source->id],
        ])->assertOk();

        $this->assertSame(0, Card::query()->where('address_book_id', $target->id)->count());
        $this->assertSame(0, AddressBookMirrorLink::query()->where('user_id', $user->id)->count());
    }

    public function test_ios_client_hides_mirrored_cards_from_carddav_reads_and_sync(): void
    {
        $user = User::factory()->create();
        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Family',
            'uri' => 'family',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-ios.vcf',
            'uid' => 'source-ios-uid',
            'etag' => md5('source-ios'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source iOS\nUID:source-ios-uid\nEMAIL:source-ios@example.test\nEND:VCARD",
        ]);

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($user);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        $context = app(DavRequestContext::class);
        $context->setAuthenticatedUser($user);
        $context->setUserAgent('CardDAVPlugin/1.0 (iPhone; iOS/18.0)');
        $backend = app(LaravelCardDavBackend::class);

        $cards = $backend->getCards($target->id);
        $this->assertCount(0, $cards);
        $this->assertNull($backend->getCard($target->id, $mirrored->uri));

        $initialChanges = $backend->getChangesForAddressBook($target->id, null, 1);
        $this->assertSame([], $initialChanges['added']);
        $this->assertSame([], $initialChanges['modified']);
        $this->assertSame([], $initialChanges['deleted']);

        $backend->updateCard(
            $source->id,
            'source-ios.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Source iOS Updated\nUID:source-ios-uid\nEMAIL:source-ios@example.test\nEND:VCARD"
        );

        $incrementalChanges = $backend->getChangesForAddressBook(
            $target->id,
            (string) $initialChanges['syncToken'],
            1,
        );

        $this->assertSame([], $incrementalChanges['added']);
        $this->assertSame([], $incrementalChanges['modified']);
        $this->assertContains($mirrored->uri, $incrementalChanges['deleted']);
    }

    public function test_macos_client_still_sees_mirrored_cards_in_carddav_reads_and_sync(): void
    {
        $user = User::factory()->create();
        $source = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'display_name' => 'Family',
            'uri' => 'family',
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-macos.vcf',
            'uid' => 'source-macos-uid',
            'etag' => md5('source-macos'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source macOS\nUID:source-macos-uid\nEMAIL:source-macos@example.test\nEND:VCARD",
        ]);

        $this->actingAs($user)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($user);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        $context = app(DavRequestContext::class);
        $context->setAuthenticatedUser($user);
        $context->setUserAgent(
            'AddressBookCore/2820 CFNetwork/1496.0.7 Darwin/23.6.0 (Mac OS X/14.6)'
        );
        $backend = app(LaravelCardDavBackend::class);

        $cards = $backend->getCards($target->id);
        $this->assertCount(1, $cards);
        $this->assertSame($mirrored->uri, $cards[0]['uri']);
        $this->assertNotNull($backend->getCard($target->id, $mirrored->uri));

        $initialChanges = $backend->getChangesForAddressBook($target->id, null, 1);
        $this->assertContains($mirrored->uri, $initialChanges['added']);
    }

    public function test_editor_permission_user_can_update_mirrored_contact_and_sync_back_to_source(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Family',
            'uri' => 'family',
            'is_sharable' => true,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'source-person.vcf',
            'uid' => 'source-person-uid',
            'etag' => md5('source-person'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Source Person\nUID:source-person-uid\nEMAIL:source@example.test\nEND:VCARD",
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Editor,
        ]);

        $this->actingAs($recipient)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($recipient);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($recipient);
        $backend = app(LaravelCardDavBackend::class);
        $backend->updateCard(
            $target->id,
            $mirrored->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Source Person Updated\nUID:".$mirrored->uid."\nEMAIL:source.updated@example.test\nEND:VCARD"
        );

        $sourceCard = Card::query()
            ->where('address_book_id', $source->id)
            ->where('uri', 'source-person.vcf')
            ->firstOrFail();
        $this->assertStringContainsString('FN:Source Person Updated', $sourceCard->data);
        $this->assertStringContainsString('EMAIL:source.updated@example.test', $sourceCard->data);

        $mirroredAfter = Card::query()->findOrFail($mirrored->id);
        $this->assertStringContainsString('FN:Source Person Updated', $mirroredAfter->data);
        $this->assertStringContainsString('EMAIL:source.updated@example.test', $mirroredAfter->data);
    }

    public function test_read_only_user_cannot_update_mirrored_contact(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Relatives',
            'uri' => 'relatives',
            'is_sharable' => true,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'relative.vcf',
            'uid' => 'relative-uid',
            'etag' => md5('relative'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Relative Original\nUID:relative-uid\nEMAIL:relative@example.test\nEND:VCARD",
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::ReadOnly,
        ]);

        $this->actingAs($recipient)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($recipient);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($recipient);
        $backend = app(LaravelCardDavBackend::class);

        $this->expectException(Forbidden::class);
        $backend->updateCard(
            $target->id,
            $mirrored->uri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Relative Updated\nUID:".$mirrored->uid."\nEMAIL:relative.updated@example.test\nEND:VCARD"
        );
    }

    public function test_editor_permission_user_can_delete_mirrored_contact_and_source_is_deleted(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $source = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'display_name' => 'Neighbors',
            'uri' => 'neighbors',
            'is_sharable' => true,
        ]);

        Card::query()->create([
            'address_book_id' => $source->id,
            'uri' => 'neighbor.vcf',
            'uid' => 'neighbor-uid',
            'etag' => md5('neighbor'),
            'size' => 1,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Neighbor\nUID:neighbor-uid\nEMAIL:neighbor@example.test\nEND:VCARD",
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $source->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $recipient->id,
            'permission' => SharePermission::Editor,
        ]);

        $this->actingAs($recipient)->patchJson('/api/address-books/apple-compat', [
            'enabled' => true,
            'source_ids' => [$source->id],
        ])->assertOk();

        $target = $this->defaultContactsBookFor($recipient);
        $mirrored = Card::query()->where('address_book_id', $target->id)->firstOrFail();

        app(DavRequestContext::class)->setAuthenticatedUser($recipient);
        $backend = app(LaravelCardDavBackend::class);
        $backend->deleteCard($target->id, $mirrored->uri);

        $this->assertDatabaseMissing('cards', [
            'address_book_id' => $source->id,
            'uri' => 'neighbor.vcf',
        ]);
        $this->assertDatabaseMissing('cards', [
            'id' => $mirrored->id,
        ]);
        $this->assertSame(0, AddressBookMirrorLink::query()->where('user_id', $recipient->id)->count());
    }

    private function defaultContactsBookFor(User $user): AddressBook
    {
        return AddressBook::query()
            ->where('owner_id', $user->id)
            ->where('is_default', true)
            ->firstOrFail();
    }
}
