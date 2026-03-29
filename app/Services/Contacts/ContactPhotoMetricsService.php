<?php

namespace App\Services\Contacts;

use App\Models\Card;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class ContactPhotoMetricsService
{
    /**
     * Records a successful saved-photo event.
     *
     * @param  array<string, mixed>  $photo
     */
    public function recordPhotoSaved(Contact $contact, array $photo, string $source): void
    {
        if (! $this->metricsEnabled()) {
            return;
        }

        Log::info('contact_photo_metric', [
            'metric' => 'photo_saved',
            'source' => $source,
            'contact_id' => (int) $contact->id,
            'owner_id' => (int) $contact->owner_id,
            'photo_bytes' => $this->normalizedBytes($photo['bytes'] ?? null),
            'thumbnail_bytes' => $this->normalizedBytes($photo['thumb_bytes'] ?? null),
            'photo_width' => $this->normalizedInt($photo['width'] ?? null),
            'photo_height' => $this->normalizedInt($photo['height'] ?? null),
            'mime' => trim((string) ($photo['mime'] ?? '')),
        ]);
    }

    /**
     * Records a photo-removed event.
     *
     * @param  array<string, mixed>|null  $photo
     */
    public function recordPhotoRemoved(Contact $contact, ?array $photo, string $source): void
    {
        if (! $this->metricsEnabled()) {
            return;
        }

        Log::info('contact_photo_metric', [
            'metric' => 'photo_removed',
            'source' => $source,
            'contact_id' => (int) $contact->id,
            'owner_id' => (int) $contact->owner_id,
            'had_photo' => $photo !== null,
            'photo_bytes' => $this->normalizedBytes(is_array($photo) ? ($photo['bytes'] ?? null) : null),
            'thumbnail_bytes' => $this->normalizedBytes(is_array($photo) ? ($photo['thumb_bytes'] ?? null) : null),
        ]);
    }

    /**
     * Records vCard payload size for distribution tracking.
     */
    public function recordVCardBuilt(
        Contact $contact,
        int $cardDataBytes,
        bool $hasPhoto,
        ?int $photoBinaryBytes = null,
    ): void {
        if (! $this->metricsEnabled()) {
            return;
        }

        Log::info('contact_photo_metric', [
            'metric' => 'vcard_built',
            'contact_id' => (int) $contact->id,
            'owner_id' => (int) $contact->owner_id,
            'cards_data_bytes' => max(0, $cardDataBytes),
            'has_photo' => $hasPhoto,
            'embedded_photo_bytes' => $photoBinaryBytes !== null ? max(0, $photoBinaryBytes) : null,
        ]);
    }

    /**
     * Summarizes current contact-photo and card payload distributions.
     *
     * @return array<string, mixed>
     */
    public function summarizeCurrentFootprint(): array
    {
        $totalContacts = 0;
        $contactsWithPhoto = 0;
        $photoBytes = [];
        $thumbnailBytes = [];

        Contact::query()
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(500, function (EloquentCollection $contacts) use (
                &$totalContacts,
                &$contactsWithPhoto,
                &$photoBytes,
                &$thumbnailBytes,
            ): void {
                foreach ($contacts as $contact) {
                    $totalContacts++;

                    $payload = is_array($contact->payload) ? $contact->payload : [];
                    $photo = $payload['photo'] ?? null;
                    if (! is_array($photo)) {
                        continue;
                    }

                    $path = trim((string) ($photo['path'] ?? ''));
                    $mime = trim((string) ($photo['mime'] ?? ''));
                    if ($path === '' || $mime === '') {
                        continue;
                    }

                    $contactsWithPhoto++;

                    $bytes = $this->normalizedBytes($photo['bytes'] ?? null);
                    if ($bytes > 0) {
                        $photoBytes[] = $bytes;
                    }

                    $thumbBytes = $this->normalizedBytes($photo['thumb_bytes'] ?? null);
                    if ($thumbBytes > 0) {
                        $thumbnailBytes[] = $thumbBytes;
                    }
                }
            });

        $cardDataBytes = [];
        Card::query()
            ->select(['id', 'size'])
            ->orderBy('id')
            ->chunkById(1000, function (EloquentCollection $cards) use (&$cardDataBytes): void {
                foreach ($cards as $card) {
                    $cardDataBytes[] = max(0, (int) $card->size);
                }
            });

        $photoCardDataBytes = [];
        Card::query()
            ->select(['id', 'size'])
            ->where('data', 'like', '%PHOTO;%')
            ->orderBy('id')
            ->chunkById(1000, function (EloquentCollection $cards) use (&$photoCardDataBytes): void {
                foreach ($cards as $card) {
                    $photoCardDataBytes[] = max(0, (int) $card->size);
                }
            });

        return [
            'generated_at' => now()->toIso8601String(),
            'contacts_total' => $totalContacts,
            'contacts_with_photo' => $contactsWithPhoto,
            'photo_coverage_percent' => $totalContacts > 0
                ? round(($contactsWithPhoto / $totalContacts) * 100, 2)
                : 0.0,
            'photo_bytes' => $this->distribution($photoBytes),
            'thumbnail_bytes' => $this->distribution($thumbnailBytes),
            'cards_data_bytes' => $this->distribution($cardDataBytes),
            'cards_with_embedded_photo_bytes' => $this->distribution($photoCardDataBytes),
            'cards_over_1mb_count' => $this->countOver($cardDataBytes, 1024 * 1024),
            'photo_cards_over_1mb_count' => $this->countOver($photoCardDataBytes, 1024 * 1024),
        ];
    }

    /**
     * Returns simple distribution fields for integer samples.
     *
     * @param  array<int, int>  $samples
     * @return array{count:int,min:int|null,p50:int|null,p95:int|null,max:int|null,avg:float|null}
     */
    private function distribution(array $samples): array
    {
        $count = count($samples);
        if ($count === 0) {
            return [
                'count' => 0,
                'min' => null,
                'p50' => null,
                'p95' => null,
                'max' => null,
                'avg' => null,
            ];
        }

        sort($samples);

        $min = $samples[0];
        $max = $samples[$count - 1];
        $sum = array_sum($samples);

        return [
            'count' => $count,
            'min' => $min,
            'p50' => $this->percentile($samples, 50),
            'p95' => $this->percentile($samples, 95),
            'max' => $max,
            'avg' => round($sum / $count, 2),
        ];
    }

    /**
     * Returns nearest-rank percentile from sorted samples.
     *
     * @param  array<int, int>  $sortedSamples
     */
    private function percentile(array $sortedSamples, int $percentile): ?int
    {
        $count = count($sortedSamples);
        if ($count === 0) {
            return null;
        }

        $rank = (int) ceil(($percentile / 100) * $count);
        $index = max(0, min($count - 1, $rank - 1));

        return $sortedSamples[$index];
    }

    /**
     * Counts samples over threshold.
     *
     * @param  array<int, int>  $samples
     */
    private function countOver(array $samples, int $threshold): int
    {
        return count(array_filter(
            $samples,
            fn (int $value): bool => $value > $threshold
        ));
    }

    /**
     * Returns normalized integer.
     */
    private function normalizedInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * Returns normalized byte count.
     */
    private function normalizedBytes(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * Returns whether metrics are enabled.
     */
    private function metricsEnabled(): bool
    {
        return (bool) config('services.contacts.photo.metrics_enabled', true);
    }
}
