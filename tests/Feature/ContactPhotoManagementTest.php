<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Contact;
use App\Models\ContactAddressBookAssignment;
use App\Models\ContactChangeRequest;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RegistrationSettingsService::class)->setContactManagementEnabled(true);
        config()->set('services.contacts.photo.disk', 'local');
    }

    public function test_user_can_stage_photo_create_contact_and_fetch_photo(): void
    {
        $this->skipWhenImagickMissing();
        Storage::fake('local');

        $user = User::factory()->create();
        $book = AddressBook::factory()->create(['owner_id' => $user->id, 'uri' => 'photo-create']);

        $token = $this->stageToken($user, null);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Photo',
            'last_name' => 'Owner',
            'address_book_ids' => [$book->id],
            'photo_upload_token' => $token,
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
        $contact = Contact::query()->findOrFail($contactId);
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $photo = $payload['photo'] ?? null;

        $this->assertIsArray($photo);
        $this->assertSame('image/jpeg', $photo['mime'] ?? null);
        Storage::disk('local')->assertExists((string) ($photo['path'] ?? ''));

        $this->actingAs($user)
            ->get('/api/contacts/'.$contactId.'/photo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contactId)
            ->where('address_book_id', $book->id)
            ->firstOrFail();
        $cardData = (string) $assignment->card?->data;
        $this->assertStringContainsString('PHOTO;ENCODING=b', $cardData);
    }

    public function test_updating_contact_with_photo_remove_deletes_file_and_card_photo(): void
    {
        $this->skipWhenImagickMissing();
        Storage::fake('local');

        $user = User::factory()->create();
        $book = AddressBook::factory()->create(['owner_id' => $user->id, 'uri' => 'photo-remove']);
        $contact = $this->createContactWithPhoto($user, $book);
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $existingPhotoPath = (string) ($payload['photo']['path'] ?? '');

        $this->actingAs($user)
            ->patchJson('/api/contacts/'.$contact->id, [
                'first_name' => 'Photo',
                'last_name' => 'Owner',
                'address_book_ids' => [$book->id],
                'photo_remove' => true,
                'phones' => [],
                'emails' => [],
                'urls' => [],
                'addresses' => [],
                'dates' => [],
                'related_names' => [],
                'instant_messages' => [],
            ])
            ->assertOk()
            ->assertJsonPath('photo', null);

        $contact->refresh();
        $updatedPayload = is_array($contact->payload) ? $contact->payload : [];
        $this->assertArrayNotHasKey('photo', $updatedPayload);
        Storage::disk('local')->assertMissing($existingPhotoPath);

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contact->id)
            ->where('address_book_id', $book->id)
            ->firstOrFail();
        $this->assertStringNotContainsString('PHOTO;', (string) $assignment->card?->data);
    }

    public function test_moderated_photo_update_is_queued_and_applied_after_approval(): void
    {
        $this->skipWhenImagickMissing();
        Storage::fake('local');
        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $book = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
            'uri' => 'photo-moderation',
        ]);

        ResourceShare::query()->create([
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $book->id,
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'permission' => SharePermission::Editor,
        ]);

        $created = $this->actingAs($owner)->postJson('/api/contacts', [
            'first_name' => 'Moderated',
            'last_name' => 'Photo',
            'address_book_ids' => [$book->id],
        ]);
        $created->assertCreated();
        $contactId = (int) $created->json('id');

        $token = $this->stageToken($editor, $contactId);

        $this->actingAs($editor)
            ->patchJson('/api/contacts/'.$contactId, [
                'first_name' => 'Moderated',
                'last_name' => 'Photo',
                'address_book_ids' => [$book->id],
                'photo_upload_token' => $token,
            ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        $contact = Contact::query()->findOrFail($contactId);
        $this->assertArrayNotHasKey('photo', is_array($contact->payload) ? $contact->payload : []);

        $requestId = (int) ContactChangeRequest::query()
            ->where('contact_id', $contactId)
            ->where('operation', 'update')
            ->where('source', 'web')
            ->where('status', 'pending')
            ->latest('id')
            ->value('id');
        $this->assertGreaterThan(0, $requestId);

        $queuedPayload = ContactChangeRequest::query()->findOrFail($requestId)->proposed_payload;
        $this->assertIsArray($queuedPayload['photo'] ?? null);
        $this->assertArrayNotHasKey('photo_upload_token', $queuedPayload);

        $this->actingAs($owner)
            ->patchJson('/api/contact-change-requests/'.$requestId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $contact->refresh();
        $appliedPayload = is_array($contact->payload) ? $contact->payload : [];
        $this->assertIsArray($appliedPayload['photo'] ?? null);

        $assignment = ContactAddressBookAssignment::query()
            ->where('contact_id', $contact->id)
            ->where('address_book_id', $book->id)
            ->firstOrFail();
        $this->assertStringContainsString('PHOTO;ENCODING=b', (string) $assignment->card?->data);
    }

    private function createContactWithPhoto(User $user, AddressBook $book): Contact
    {
        $token = $this->stageToken($user, null);

        $created = $this->actingAs($user)->postJson('/api/contacts', [
            'first_name' => 'Photo',
            'last_name' => 'Owner',
            'address_book_ids' => [$book->id],
            'photo_upload_token' => $token,
            'phones' => [],
            'emails' => [],
            'urls' => [],
            'addresses' => [],
            'dates' => [],
            'related_names' => [],
            'instant_messages' => [],
        ]);
        $created->assertCreated();

        return Contact::query()->findOrFail((int) $created->json('id'));
    }

    private function stageToken(User $actor, ?int $contactId): string
    {
        $response = $this->actingAs($actor)->post(
            $contactId === null ? '/api/contacts/photos/stage' : '/api/contacts/'.$contactId.'/photo/stage',
            [
                'photo' => $this->imageUpload(1200, 1200),
                'crop_x' => 0,
                'crop_y' => 0,
                'crop_width' => 1200,
                'crop_height' => 1200,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertCreated();

        return (string) $response->json('token');
    }

    private function imageUpload(int $width, int $height): UploadedFile
    {
        $image = new \Imagick;
        $image->newImage($width, $height, new \ImagickPixel('#4f46e5'));
        $image->setImageFormat('jpeg');
        $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $image->setImageCompressionQuality(92);
        $binary = (string) $image->getImageBlob();
        $image->clear();
        $image->destroy();

        $path = tempnam(sys_get_temp_dir(), 'davvy-photo-');
        file_put_contents($path, $binary);

        return new UploadedFile(
            $path,
            'contact.jpg',
            'image/jpeg',
            null,
            true,
        );
    }

    private function skipWhenImagickMissing(): void
    {
        if (class_exists(\Imagick::class)) {
            return;
        }

        $this->markTestSkipped('Imagick is required for managed contact photo tests.');
    }
}
