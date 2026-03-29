<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\Card;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ContactPhotoMetricsSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_summary_command_logs_photo_and_card_size_distributions(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $addressBook = AddressBook::factory()->create([
            'owner_id' => $user->id,
            'uri' => 'photo-metric-rollup',
        ]);

        Contact::query()->create([
            'owner_id' => $user->id,
            'uid' => 'metric-contact-1',
            'full_name' => 'Metric Contact 1',
            'payload' => [
                'first_name' => 'Metric',
                'last_name' => 'Contact',
                'photo' => [
                    'disk' => 'local',
                    'path' => 'contacts/photos/final/user-1/contact-1/photo-a.jpg',
                    'thumb_path' => 'contacts/photos/final/user-1/contact-1/thumb/photo-a.jpg',
                    'mime' => 'image/jpeg',
                    'width' => 1024,
                    'height' => 1024,
                    'bytes' => 120000,
                    'thumb_bytes' => 14000,
                ],
            ],
        ]);

        Contact::query()->create([
            'owner_id' => $user->id,
            'uid' => 'metric-contact-2',
            'full_name' => 'Metric Contact 2',
            'payload' => [
                'first_name' => 'No',
                'last_name' => 'Photo',
            ],
        ]);

        Card::query()->create([
            'address_book_id' => $addressBook->id,
            'uri' => 'metric-card-1.vcf',
            'uid' => 'metric-card-uid-1',
            'etag' => sha1('metric-card-1'),
            'size' => 1500,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Metric One\nUID:metric-card-uid-1\nPHOTO;ENCODING=b;TYPE=JPEG:AA==\nEND:VCARD",
        ]);
        Card::query()->create([
            'address_book_id' => $addressBook->id,
            'uri' => 'metric-card-2.vcf',
            'uid' => 'metric-card-uid-2',
            'etag' => sha1('metric-card-2'),
            'size' => 600,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Metric Two\nUID:metric-card-uid-2\nEND:VCARD",
        ]);
        Card::query()->create([
            'address_book_id' => $addressBook->id,
            'uri' => 'metric-card-3.vcf',
            'uid' => 'metric-card-uid-3',
            'etag' => sha1('metric-card-3'),
            'size' => 1200000,
            'data' => "BEGIN:VCARD\nVERSION:4.0\nFN:Metric Three\nUID:metric-card-uid-3\nPHOTO;ENCODING=b;TYPE=JPEG:BB==\nEND:VCARD",
        ]);

        $this->artisan('app:contacts:photos:metrics-summary')->assertExitCode(0);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'contact_photo_metric_summary'
                && (int) ($context['contacts_total'] ?? 0) === 2
                && (int) ($context['contacts_with_photo'] ?? 0) === 1
                && (int) ($context['photo_bytes']['count'] ?? 0) === 1
                && (int) ($context['photo_bytes']['p50'] ?? 0) === 120000
                && (int) ($context['thumbnail_bytes']['p50'] ?? 0) === 14000
                && (int) ($context['cards_data_bytes']['count'] ?? 0) === 3
                && (int) ($context['cards_data_bytes']['p95'] ?? 0) === 1200000
                && (int) ($context['cards_with_embedded_photo_bytes']['count'] ?? 0) === 2
                && (int) ($context['cards_with_embedded_photo_bytes']['p95'] ?? 0) === 1200000
                && (int) ($context['cards_over_1mb_count'] ?? 0) === 1
                && (int) ($context['photo_cards_over_1mb_count'] ?? 0) === 1
        )->once();
    }
}
