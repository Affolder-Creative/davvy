<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Card;
use App\Models\Contact;
use App\Models\ContactAddressBookAssignment;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\Contacts\ContactMilestoneCalendarService;
use App\Services\Contacts\ManagedContactSyncService;
use App\Services\Dav\Backends\LaravelAuthBackend;
use App\Services\Dav\Backends\LaravelCardDavBackend;
use App\Services\DavRequestContext;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Sabre\HTTP\Request as SabreRequest;
use Sabre\HTTP\Response as SabreResponse;
use Tests\TestCase;

class ContactCardDavSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RegistrationSettingsService::class)->setContactManagementEnabled(true);
    }

    public function test_creating_card_via_carddav_backfills_managed_contact(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'sync-book',
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->createCard(
            $addressBook->id,
            'alex-sync.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Alex Sync\nN:Sync;Alex;;;\nUID:carddav-sync-uid\nORG:Davvy Labs;Platform\nTITLE:Engineer\nTEL;TYPE=CELL:+15555550100\nEMAIL;TYPE=WORK:alex.sync@example.com\nEND:VCARD"
        );

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', 'alex-sync.vcf')
            ->firstOrFail();

        $contact = Contact::query()
            ->where('owner_id', $user->id)
            ->where('uid', 'carddav-sync-uid')
            ->first();

        $this->assertNotNull($contact);
        $this->assertSame('Alex', $contact->payload['first_name'] ?? null);
        $this->assertSame('Sync', $contact->payload['last_name'] ?? null);
        $this->assertSame('Davvy Labs', $contact->payload['company'] ?? null);
        $this->assertSame('Platform', $contact->payload['department'] ?? null);
        $this->assertSame('+15555550100', $contact->payload['phones'][0]['value'] ?? null);
        $this->assertSame('alex.sync@example.com', $contact->payload['emails'][0]['value'] ?? null);
        $this->assertFalse((bool) ($contact->payload['head_of_household'] ?? false));
        $this->assertFalse((bool) ($contact->payload['exclude_milestone_calendars'] ?? false));

        $this->assertDatabaseHas('contact_address_book_assignments', [
            'contact_id' => $contact->id,
            'address_book_id' => $addressBook->id,
            'card_id' => $card->id,
            'card_uri' => 'alex-sync.vcf',
        ]);
    }

    public function test_shared_editor_carddav_create_ignores_managed_owner_hint(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $sharedBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
            'uri' => 'shared-owner-hint-book',
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $sharedBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($editor);

        app(LaravelCardDavBackend::class)->createCard(
            $sharedBook->id,
            'owner-hint.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Hinted Owner\nN:Owner;Hinted;;;\nUID:owner-hint-uid\nX-DAVVY-CONTACT-OWNER:{$editor->id}\nEND:VCARD"
        );

        $contact = Contact::query()
            ->where('uid', 'owner-hint-uid')
            ->first();

        $this->assertNotNull($contact);
        $this->assertSame($owner->id, (int) $contact->owner_id);
        $this->assertDatabaseHas('contact_address_book_assignments', [
            'contact_id' => $contact->id,
            'address_book_id' => $sharedBook->id,
            'card_uri' => 'owner-hint.vcf',
        ]);
    }

    public function test_shared_editor_carddav_create_ignores_managed_contact_hint(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $sharedBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
            'uri' => 'shared-contact-hint-book',
        ]);
        $privateBook = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'uri' => 'private-contact-hint-book',
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $sharedBook->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        $victimCreated = $this->actingAs($owner)->postJson('/api/contacts', [
            'first_name' => 'Victim',
            'last_name' => 'Contact',
            'address_book_ids' => [$privateBook->id],
        ]);
        $victimCreated->assertCreated();

        $victimId = (int) $victimCreated->json('id');

        app(DavRequestContext::class)->setAuthenticatedUser($editor);

        app(LaravelCardDavBackend::class)->createCard(
            $sharedBook->id,
            'contact-hint.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Injected Name\nN:Name;Injected;;;\nUID:contact-hint-uid\nX-DAVVY-CONTACT-OWNER:{$owner->id}\nX-DAVVY-CONTACT-ID:{$victimId}\nEND:VCARD"
        );

        $victim = Contact::query()->findOrFail($victimId);
        $this->assertSame('Victim', $victim->payload['first_name'] ?? null);
        $this->assertDatabaseMissing('contact_address_book_assignments', [
            'contact_id' => $victimId,
            'address_book_id' => $sharedBook->id,
        ]);

        $created = Contact::query()
            ->where('owner_id', $owner->id)
            ->where('uid', 'contact-hint-uid')
            ->first();
        $this->assertNotNull($created);
        $this->assertNotSame($victimId, (int) $created->id);
        $this->assertDatabaseHas('contact_address_book_assignments', [
            'contact_id' => $created->id,
            'address_book_id' => $sharedBook->id,
            'card_uri' => 'contact-hint.vcf',
        ]);
    }

    public function test_updating_card_via_carddav_rehydrates_existing_managed_contact_payload(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'rehydrate-book',
        ]);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Original',
            'last_name' => 'Person',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        $contactId = (int) $created->json('id');
        $uid = (string) $created->json('uid');
        $cardUri = (string) ContactAddressBookAssignment::query()
            ->where('contact_id', $contactId)
            ->where('address_book_id', $addressBook->id)
            ->value('card_uri');

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            $cardUri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Jordan Parker\nN:Parker;Jordan;;;\nUID:{$uid}\nORG:Acme Co;Research\nTITLE:Lead Engineer\nTEL;TYPE=CELL:+15555550111\nEMAIL;TYPE=WORK:jordan.parker@example.com\nX-DAVVY-PRONOUNS:they/them\nX-DAVVY-HEAD-OF-HOUSEHOLD:1\nX-DAVVY-EXCLUDE-MILESTONES:1\nEND:VCARD"
        );

        $contact = Contact::query()->findOrFail($contactId);
        $payload = is_array($contact->payload) ? $contact->payload : [];

        $this->assertSame('Jordan', $payload['first_name'] ?? null);
        $this->assertSame('Parker', $payload['last_name'] ?? null);
        $this->assertSame('Acme Co', $payload['company'] ?? null);
        $this->assertSame('Research', $payload['department'] ?? null);
        $this->assertSame('Lead Engineer', $payload['job_title'] ?? null);
        $this->assertSame('they/them', $payload['pronouns'] ?? null);
        $this->assertSame('+15555550111', $payload['phones'][0]['value'] ?? null);
        $this->assertSame('jordan.parker@example.com', $payload['emails'][0]['value'] ?? null);
        $this->assertTrue((bool) ($payload['head_of_household'] ?? false));
        $this->assertTrue((bool) ($payload['exclude_milestone_calendars'] ?? false));
    }

    public function test_related_name_contact_id_round_trips_through_carddav(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'related-roundtrip',
        ]);

        $partner = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Jamie',
            'last_name' => 'Rowe',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $partner->assertCreated();

        $partnerId = (int) $partner->json('id');
        $partnerName = (string) $partner->json('display_name');

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [
                [
                    'label' => 'spouse',
                    'custom_label' => null,
                    'value' => 'Will be replaced',
                    'related_contact_id' => $partnerId,
                ],
                [
                    'label' => 'custom',
                    'custom_label' => 'Daughter-in-Law',
                    'value' => 'Talia Kay Hargrove',
                    'related_contact_id' => null,
                ],
                [
                    'label' => 'custom',
                    'custom_label' => 'Son',
                    'value' => 'Gavin Calvin Hargrove',
                    'related_contact_id' => null,
                ],
            ],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        $contactId = (int) $created->json('id');
        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contactId)
            ->where('address_book_id', $addressBook->id)
            ->firstOrFail();
        $card = Card::query()->findOrFail($assignment->card_id);

        $this->assertStringContainsString(
            'X-DAVVY-RELATED-CONTACT-ID='.$partnerId,
            (string) $card->data,
        );
        $this->assertStringContainsString('RELATED;TYPE=SPOUSE', (string) $card->data);
        $this->assertStringContainsString('X-ABLABEL=Daughter-in-Law', (string) $card->data);
        $this->assertStringContainsString(
            'RELATED;TYPE=CHILD;X-ABLABEL=Son:Gavin Calvin Hargrove',
            (string) $card->data,
        );

        app(DavRequestContext::class)->setAuthenticatedUser($user);
        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            (string) $assignment->card_uri,
            (string) $card->data,
        );

        $payload = Contact::query()->findOrFail($contactId)->payload;
        $this->assertSame($partnerId, $payload['related_names'][0]['related_contact_id'] ?? null);
        $this->assertSame($partnerName, $payload['related_names'][0]['value'] ?? null);
    }

    public function test_carddav_round_trip_keeps_canonical_tokens_under_spanish_locale(): void
    {
        $user = User::factory()->create([
            'email' => 'canonical-es@example.test',
            'password' => 'password1234',
            'locale' => 'es',
        ]);
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'canonical-es-roundtrip',
        ]);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [
                [
                    'label' => 'son',
                    'custom_label' => null,
                    'value' => 'Gavin Rivera',
                    'related_contact_id' => null,
                ],
                [
                    'label' => 'custom',
                    'custom_label' => 'Daughter-in-Law',
                    'value' => 'Talia Rivera',
                    'related_contact_id' => null,
                ],
            ],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        $uid = (string) $created->json('uid');
        $contactId = (int) $created->json('id');

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contactId)
            ->where('address_book_id', $addressBook->id)
            ->firstOrFail();

        $card = Card::query()->findOrFail($assignment->card_id);
        $initialCardData = (string) $card->data;

        $authBackend = app(LaravelAuthBackend::class);
        $request = new SabreRequest('PROPFIND', '/dav/', [
            'Authorization' => 'Basic '.base64_encode($user->email.':password1234'),
        ]);
        $response = new SabreResponse;

        [$ok] = $authBackend->check($request, $response);

        $this->assertTrue($ok);
        $this->assertSame('es', app()->getLocale());
        $this->assertStringContainsString('UID:'.$uid, $initialCardData);
        $this->assertStringContainsString('RELATED;TYPE=CHILD', $initialCardData);
        $this->assertStringContainsString('X-ABLABEL=Son', $initialCardData);
        $this->assertStringContainsString('X-ABLABEL=Daughter-in-Law', $initialCardData);

        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            (string) $assignment->card_uri,
            $initialCardData,
        );

        $roundTrippedCardData = (string) Card::query()->findOrFail($assignment->card_id)->fresh()->data;

        $this->assertStringContainsString('UID:'.$uid, $roundTrippedCardData);
        $this->assertStringContainsString('RELATED;TYPE=CHILD', $roundTrippedCardData);
        $this->assertStringContainsString('X-ABLABEL=Son', $roundTrippedCardData);
        $this->assertStringContainsString('X-ABLABEL=Daughter-in-Law', $roundTrippedCardData);
    }

    public function test_carddav_photo_updates_and_removes_managed_contact_photo_payload(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is required for CardDAV photo parsing.');
        }

        Storage::fake('local');
        config()->set('services.contacts.photo.disk', 'local');

        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'photo-roundtrip',
        ]);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Jordan',
            'last_name' => 'Photo',
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        $contactId = (int) $created->json('id');
        $uid = (string) $created->json('uid');
        $cardUri = (string) ContactAddressBookAssignment::query()
            ->where('contact_id', $contactId)
            ->where('address_book_id', $addressBook->id)
            ->value('card_uri');

        $image = new \Imagick;
        $image->newImage(1400, 900, new \ImagickPixel('#0891b2'));
        $image->setImageFormat('jpeg');
        $photoBase64 = base64_encode((string) $image->getImageBlob());
        $image->clear();
        $image->destroy();

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            $cardUri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Jordan Photo\nN:Photo;Jordan;;;\nUID:{$uid}\nPHOTO;ENCODING=b;TYPE=JPEG:{$photoBase64}\nEND:VCARD"
        );

        $contact = Contact::query()->findOrFail($contactId);
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $this->assertIsArray($payload['photo'] ?? null);
        $this->assertSame('image/jpeg', $payload['photo']['mime'] ?? null);
        Storage::disk('local')->assertExists((string) ($payload['photo']['path'] ?? ''));
        Storage::disk('local')->assertExists((string) ($payload['photo']['thumb_path'] ?? ''));

        $photoPath = (string) ($payload['photo']['path'] ?? '');

        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            $cardUri,
            "BEGIN:VCARD\nVERSION:4.0\nFN:Jordan Photo\nN:Photo;Jordan;;;\nUID:{$uid}\nEND:VCARD"
        );

        $contact->refresh();
        $updatedPayload = is_array($contact->payload) ? $contact->payload : [];
        $this->assertArrayNotHasKey('photo', $updatedPayload);
        Storage::disk('local')->assertMissing($photoPath);
    }

    public function test_carddav_categories_sync_into_managed_payload_and_round_trip_through_web_save(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'categories-sync',
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->createCard(
            $addressBook->id,
            'categories-sync.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Category Person\nN:Person;Category;;;\nUID:category-sync-uid\nCATEGORIES:Family,Work\nCATEGORIES:work,Friends\nEND:VCARD"
        );

        $contact = Contact::query()
            ->where('owner_id', $user->id)
            ->where('uid', 'category-sync-uid')
            ->firstOrFail();
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $this->assertSame(['Family', 'Work', 'Friends'], $payload['categories'] ?? []);

        $this->actingAs($user)->patchJson('/api/contacts/'.$contact->id, [
            ...$payload,
            'first_name' => 'Category',
            'last_name' => 'Person',
            'address_book_ids' => [$addressBook->id],
        ])->assertOk();

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contact->id)
            ->where('address_book_id', $addressBook->id)
            ->firstOrFail();
        $card = Card::query()->findOrFail($assignment->card_id);
        $this->assertStringContainsString('CATEGORIES:Family,Work,Friends', (string) $card->data);
    }

    public function test_related_name_synonym_label_round_trips_through_carddav(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'related-synonym-roundtrip',
        ]);

        $child = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Riley',
            'last_name' => 'Morgan',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $child->assertCreated();
        $childId = (int) $child->json('id');
        $childName = (string) $child->json('display_name');

        $parent = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Pat',
            'last_name' => 'Morgan',
            'company' => null,
            'address_book_ids' => [$addressBook->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [
                [
                    'label' => 'son',
                    'custom_label' => null,
                    'value' => 'Will be replaced',
                    'related_contact_id' => $childId,
                ],
            ],
            'instant_messages' => [],
        ]);
        $parent->assertCreated();

        $parentId = (int) $parent->json('id');

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $parentId)
            ->where('address_book_id', $addressBook->id)
            ->firstOrFail();
        $card = Card::query()->findOrFail($assignment->card_id);
        $cardData = (string) $card->data;

        $this->assertStringContainsString('RELATED;TYPE=CHILD', $cardData);
        $this->assertStringContainsString('X-ABLABEL=Son', $cardData);
        $this->assertStringContainsString(
            'X-DAVVY-RELATED-CONTACT-ID='.$childId,
            $cardData,
        );

        app(DavRequestContext::class)->setAuthenticatedUser($user);
        app(LaravelCardDavBackend::class)->updateCard(
            $addressBook->id,
            (string) $assignment->card_uri,
            $cardData,
        );

        $payload = Contact::query()->findOrFail($parentId)->payload;
        $this->assertSame('son', $payload['related_names'][0]['label'] ?? null);
        $this->assertSame(null, $payload['related_names'][0]['custom_label'] ?? null);
        $this->assertSame($childId, $payload['related_names'][0]['related_contact_id'] ?? null);
        $this->assertSame($childName, $payload['related_names'][0]['value'] ?? null);
    }

    public function test_reassigning_card_updates_milestones_for_orphan_cleanup_related_contacts(): void
    {
        $user = User::factory()->create();
        $bookA = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'reassign-source-book',
        ]);
        $bookB = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'reassign-related-book',
        ]);

        $target = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Target',
            'last_name' => 'Contact',
            'company' => null,
            'address_book_ids' => [$bookB->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $target->assertCreated();
        $targetId = (int) $target->json('id');
        $targetUid = (string) $target->json('uid');

        $linked = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Linked',
            'last_name' => 'Relative',
            'company' => null,
            'address_book_ids' => [$bookB->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $linked->assertCreated();
        $linkedId = (int) $linked->json('id');
        $linkedName = (string) $linked->json('display_name');

        $old = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Old',
            'last_name' => 'Contact',
            'company' => null,
            'address_book_ids' => [$bookA->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $old->assertCreated();
        $oldId = (int) $old->json('id');

        $this->actingAs($user)->patchJson('/api/contacts/'.$oldId, [
            'first_name' => 'Old',
            'last_name' => 'Contact',
            'company' => null,
            'address_book_ids' => [$bookA->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [[
                'label' => 'friend',
                'custom_label' => null,
                'value' => $linkedName,
                'related_contact_id' => $linkedId,
            ]],
            'instant_messages' => [],
        ])->assertOk();

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $oldId)
            ->where('address_book_id', $bookA->id)
            ->firstOrFail();
        $card = Card::query()->findOrFail($assignment->card_id);
        $cardData = "BEGIN:VCARD\nVERSION:4.0\nFN:Target Contact\nN:Contact;Target;;;\nUID:{$targetUid}\nEND:VCARD";
        $card->update([
            'uid' => $targetUid,
            'etag' => md5($cardData),
            'size' => strlen($cardData),
            'data' => $cardData,
        ]);

        $capturedSyncIds = [];
        $this->mock(ContactMilestoneCalendarService::class, function (MockInterface $mock) use (&$capturedSyncIds): void {
            $mock->shouldReceive('syncAddressBooksByIds')
                ->once()
                ->withArgs(function (array $addressBookIds) use (&$capturedSyncIds): bool {
                    $capturedSyncIds = $addressBookIds;

                    return true;
                });
        });

        app(ManagedContactSyncService::class)->syncCardUpsert(
            $bookA->fresh(),
            $card->fresh(),
            $user,
        );

        $this->assertDatabaseMissing('contacts', ['id' => $oldId]);
        $this->assertDatabaseHas('contact_address_book_assignments', [
            'contact_id' => $targetId,
            'address_book_id' => $bookA->id,
            'card_id' => $card->id,
        ]);

        $linkedPayload = Contact::query()->findOrFail($linkedId)->payload;
        $linkedRelatedRows = is_array($linkedPayload['related_names'] ?? null)
            ? $linkedPayload['related_names']
            : [];
        $this->assertSame([], $linkedRelatedRows);

        $normalizedSyncIds = collect($capturedSyncIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->assertContains($bookA->id, $normalizedSyncIds);
        $this->assertContains($bookB->id, $normalizedSyncIds);
    }

    public function test_deleting_card_via_carddav_removes_orphaned_managed_contact(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'delete-sync-book',
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->createCard(
            $addressBook->id,
            'delete-sync.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Delete Sync\nN:Sync;Delete;;;\nUID:delete-sync-uid\nEMAIL:delete.sync@example.com\nEND:VCARD"
        );

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', 'delete-sync.vcf')
            ->firstOrFail();

        $assignment = ContactAddressBookAssignment::query()
            ->where('card_id', $card->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertDatabaseHas('contacts', ['id' => $assignment->contact_id]);

        app(LaravelCardDavBackend::class)->deleteCard($addressBook->id, 'delete-sync.vcf');

        $this->assertDatabaseMissing('contact_address_book_assignments', ['card_id' => $card->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $assignment->contact_id]);
    }

    public function test_deleting_address_book_via_api_removes_orphaned_managed_contact(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'is_default' => false,
            'uri' => 'delete-book-api-sync',
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->createCard(
            $addressBook->id,
            'delete-book-api-sync.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Delete Book Sync\nN:Sync;Delete Book;;;\nUID:delete-book-api-sync-uid\nEMAIL:delete.book.sync@example.com\nEND:VCARD"
        );

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', 'delete-book-api-sync.vcf')
            ->firstOrFail();

        $assignment = ContactAddressBookAssignment::query()
            ->where('card_id', $card->id)
            ->firstOrFail();

        $this->assertDatabaseHas('contacts', ['id' => $assignment->contact_id]);

        $this->actingAs($user)
            ->deleteJson('/api/address-books/'.$addressBook->id)
            ->assertOk();

        $this->assertDatabaseMissing('address_books', ['id' => $addressBook->id]);
        $this->assertDatabaseMissing('contact_address_book_assignments', ['card_id' => $card->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $assignment->contact_id]);
    }

    public function test_deleting_address_book_via_carddav_removes_orphaned_managed_contact(): void
    {
        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'is_default' => false,
            'uri' => 'delete-book-dav-sync',
        ]);

        app(DavRequestContext::class)->setAuthenticatedUser($user);

        app(LaravelCardDavBackend::class)->createCard(
            $addressBook->id,
            'delete-book-dav-sync.vcf',
            "BEGIN:VCARD\nVERSION:4.0\nFN:Delete DAV Book Sync\nN:Sync;Delete DAV Book;;;\nUID:delete-book-dav-sync-uid\nEMAIL:delete.dav.book.sync@example.com\nEND:VCARD"
        );

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', 'delete-book-dav-sync.vcf')
            ->firstOrFail();

        $assignment = ContactAddressBookAssignment::query()
            ->where('card_id', $card->id)
            ->firstOrFail();

        $this->assertDatabaseHas('contacts', ['id' => $assignment->contact_id]);

        app(LaravelCardDavBackend::class)->deleteAddressBook($addressBook->id);

        $this->assertDatabaseMissing('address_books', ['id' => $addressBook->id]);
        $this->assertDatabaseMissing('contact_address_book_assignments', ['card_id' => $card->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $assignment->contact_id]);
    }

    public function test_deleting_address_book_keeps_contact_when_other_assignments_exist(): void
    {
        $user = User::factory()->create();
        $bookA = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'is_default' => false,
            'uri' => 'delete-book-keep-contact-a',
        ]);
        $bookB = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'is_default' => false,
            'uri' => 'delete-book-keep-contact-b',
        ]);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Multi',
            'last_name' => 'Book Contact',
            'company' => null,
            'address_book_ids' => [$bookA->id, $bookB->id],
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        $contactId = (int) $created->json('id');

        $this->actingAs($user)
            ->deleteJson('/api/address-books/'.$bookA->id)
            ->assertOk();

        $this->assertDatabaseMissing('address_books', ['id' => $bookA->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contactId]);
        $this->assertDatabaseMissing('contact_address_book_assignments', [
            'contact_id' => $contactId,
            'address_book_id' => $bookA->id,
        ]);
        $this->assertDatabaseHas('contact_address_book_assignments', [
            'contact_id' => $contactId,
            'address_book_id' => $bookB->id,
        ]);
    }
}
