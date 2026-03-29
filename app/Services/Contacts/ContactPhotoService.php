<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactPhotoUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagick;
use RuntimeException;

class ContactPhotoService
{
    public function __construct(
        private readonly ContactPhotoMetricsService $contactPhotoMetricsService,
    ) {}

    /**
     * Stages a cropped + normalized contact photo upload.
     *
     * @param  array{x:int,y:int,width:int,height:int}  $crop
     */
    public function stageUpload(
        User $actor,
        UploadedFile $file,
        array $crop,
        ?Contact $contact = null,
    ): ContactPhotoUpload {
        $this->assertImagickAvailable();
        $this->assertMimeAllowed($file);

        $maxUploadBytes = $this->maxUploadBytes();
        if ($file->getSize() > $maxUploadBytes) {
            throw ValidationException::withMessages([
                'photo' => ['Photo exceeds the maximum upload size.'],
            ]);
        }

        $normalized = $this->normalizeUploadedImage($file, $crop);
        $token = (string) Str::uuid();
        $disk = $this->photoDisk();
        $stagedPath = $this->stagedPath($actor->id, $token);

        Storage::disk($disk)->put($stagedPath, $normalized['binary']);

        return ContactPhotoUpload::query()->create([
            'token' => $token,
            'user_id' => $actor->id,
            'contact_id' => $contact?->id,
            'disk' => $disk,
            'path' => $stagedPath,
            'mime' => $normalized['mime'],
            'width' => $normalized['width'],
            'height' => $normalized['height'],
            'bytes' => $normalized['bytes'],
            'sha256' => $normalized['sha256'],
            'expires_at' => now()->addMinutes($this->stageTtlMinutes()),
            'consumed_at' => null,
        ]);
    }

    /**
     * Applies web photo mutation controls to payload before contact persistence.
     *
     * @param  array<string, mixed>  $incomingPayload
     * @return array<string, mixed>
     */
    public function applyWebPayloadMutation(
        ?User $actor,
        ?Contact $contact,
        array $incomingPayload,
    ): array {
        if (! $contact) {
            throw ValidationException::withMessages([
                'photo_upload_token' => ['Photo token cannot be consumed before contact creation.'],
            ]);
        }

        return $this->preparePayloadForPersistence($actor, $contact, $incomingPayload);
    }

    /**
     * Prepares payload for persistence, applying upload/remove controls and preserving photo state.
     *
     * @param  array<string, mixed>  $incomingPayload
     * @return array<string, mixed>
     */
    public function preparePayloadForPersistence(
        ?User $actor,
        Contact $contact,
        array $incomingPayload,
    ): array {
        $payload = $incomingPayload;
        $currentPayload = is_array($contact->payload) ? $contact->payload : [];
        $existingPhoto = $this->photoFromPayload($currentPayload);

        $token = $this->cleanString($payload['photo_upload_token'] ?? null);
        $removePhoto = filter_var($payload['photo_remove'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $incomingPhoto = $this->photoFromPayload(is_array($payload) ? $payload : []);

        unset($payload['photo_upload_token'], $payload['photo_remove'], $payload['photo']);

        if ($removePhoto) {
            if ($existingPhoto !== null) {
                $this->deletePhotoFile($existingPhoto);
                $this->contactPhotoMetricsService->recordPhotoRemoved($contact, $existingPhoto, 'web_remove');
            }

            return $payload;
        }

        if ($token !== null) {
            $upload = $this->activeUploadByToken(
                token: $token,
                actor: $actor,
                contact: $contact,
            );

            $photo = $this->promoteUploadToFinalPhoto($upload, $contact);

            if ($existingPhoto !== null && $this->photoPath($existingPhoto) !== $photo['path']) {
                $this->deletePhotoFile($existingPhoto);
                $this->contactPhotoMetricsService->recordPhotoRemoved($contact, $existingPhoto, 'web_replace');
            }

            $payload['photo'] = $photo;
            if ($existingPhoto === null || $this->photoPath($existingPhoto) !== $photo['path']) {
                $this->contactPhotoMetricsService->recordPhotoSaved($contact, $photo, 'web_stage_token');
            }

            return $payload;
        }

        if ($incomingPhoto !== null) {
            $payload['photo'] = $incomingPhoto;

            if ($existingPhoto !== null && $this->photoPath($existingPhoto) !== $this->photoPath($incomingPhoto)) {
                $this->deletePhotoFile($existingPhoto);
                $this->contactPhotoMetricsService->recordPhotoRemoved($contact, $existingPhoto, 'web_payload_replace');
            }

            if ($existingPhoto === null || $this->photoPath($existingPhoto) !== $this->photoPath($incomingPhoto)) {
                $this->contactPhotoMetricsService->recordPhotoSaved($contact, $incomingPhoto, 'web_payload');
            }

            return $payload;
        }

        if ($existingPhoto !== null) {
            $payload['photo'] = $existingPhoto;
        }

        return $payload;
    }

    /**
     * Materializes queued web updates so moderation approval does not depend on stage-token expiry.
     *
     * @param  array<string, mixed>  $incomingPayload
     * @return array<string, mixed>
     */
    public function prepareWebPayloadForModeration(
        User $actor,
        Contact $contact,
        array $incomingPayload,
    ): array {
        $payload = $incomingPayload;
        $token = $this->cleanString($payload['photo_upload_token'] ?? null);
        $removePhoto = filter_var($payload['photo_remove'] ?? false, FILTER_VALIDATE_BOOLEAN);

        unset($payload['photo']);

        if ($removePhoto) {
            unset($payload['photo_upload_token']);
            $payload['photo_remove'] = true;

            return $payload;
        }

        if ($token === null) {
            unset($payload['photo_upload_token'], $payload['photo_remove']);

            return $payload;
        }

        $upload = $this->activeUploadByToken(
            token: $token,
            actor: $actor,
            contact: $contact,
        );
        $payload['photo'] = $this->promoteUploadToFinalPhoto($upload, $contact);
        unset($payload['photo_upload_token'], $payload['photo_remove']);

        return $payload;
    }

    /**
     * Materializes queued CardDAV updates while deferring destructive deletes until approval.
     *
     * @param  array<string, mixed>  $incomingPayload
     * @param  array{binary:string,mime:string,sha256:string,width:int,height:int}|null  $parsedPhoto
     * @return array<string, mixed>
     */
    public function prepareCardDavPayloadForModeration(
        Contact $contact,
        array $incomingPayload,
        ?array $parsedPhoto,
    ): array {
        $payload = $incomingPayload;
        unset($payload['photo_upload_token'], $payload['photo_remove'], $payload['photo']);

        if ($parsedPhoto === null) {
            $payload['photo_remove'] = true;

            return $payload;
        }

        $photo = $this->storeParsedPhoto($contact, $parsedPhoto);
        if ($photo !== null) {
            $payload['photo'] = $photo;
        }
        unset($payload['photo_remove']);

        return $payload;
    }

    /**
     * Applies parsed CardDAV photo content to contact payload.
     *
     * @param  array<string, mixed>  $incomingPayload
     * @param  array{binary:string,mime:string,sha256:string,width:int,height:int}|null  $parsedPhoto
     * @return array<string, mixed>
     */
    public function applyParsedCardDavPhoto(
        Contact $contact,
        array $incomingPayload,
        ?array $parsedPhoto,
    ): array {
        $payload = $incomingPayload;
        unset($payload['photo_upload_token'], $payload['photo_remove'], $payload['photo']);

        $currentPayload = is_array($contact->payload) ? $contact->payload : [];
        $existingPhoto = $this->photoFromPayload($currentPayload);

        if ($parsedPhoto === null) {
            if ($existingPhoto !== null) {
                $this->deletePhotoFile($existingPhoto);
                $this->contactPhotoMetricsService->recordPhotoRemoved($contact, $existingPhoto, 'carddav_remove');
            }

            return $payload;
        }

        $photo = $this->storeParsedPhoto($contact, $parsedPhoto);
        if ($photo === null) {
            if ($existingPhoto !== null) {
                $payload['photo'] = $existingPhoto;
            }

            return $payload;
        }

        if ($existingPhoto !== null && $this->photoPath($existingPhoto) !== $photo['path']) {
            $this->deletePhotoFile($existingPhoto);
            $this->contactPhotoMetricsService->recordPhotoRemoved($contact, $existingPhoto, 'carddav_replace');
        }

        $payload['photo'] = $photo;
        if ($existingPhoto === null || $this->photoPath($existingPhoto) !== $photo['path']) {
            $this->contactPhotoMetricsService->recordPhotoSaved($contact, $photo, 'carddav_photo');
        }

        return $payload;
    }

    /**
     * Deletes a persisted photo file from a payload, if present.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deletePhotoFromPayload(array $payload): void
    {
        $photo = $this->photoFromPayload($payload);
        if ($photo === null) {
            return;
        }

        $this->deletePhotoFile($photo);
    }

    /**
     * Returns public photo metadata for API serialization.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *   url:string,
     *   thumbnail_url:string,
     *   width:int,
     *   height:int,
     *   thumbnail_width:int,
     *   thumbnail_height:int,
     *   mime:string,
     *   bytes:int,
     *   thumbnail_bytes:int,
     *   version:string
     * }|null
     */
    public function publicPhotoData(array $payload, int $contactId): ?array
    {
        $photo = $this->photoFromPayload($payload);
        if ($photo === null) {
            return null;
        }

        $version = $this->cleanString($photo['version'] ?? null)
            ?? substr((string) ($photo['sha256'] ?? sha1((string) ($photo['path'] ?? 'photo'))), 0, 16);
        $thumbnailVersion = $this->cleanString($photo['thumb_version'] ?? null)
            ?? substr((string) ($photo['thumb_sha256'] ?? $version), 0, 16);

        return [
            'url' => '/api/contacts/'.$contactId.'/photo?v='.$version,
            'thumbnail_url' => '/api/contacts/'.$contactId.'/photo?variant=thumb&v='.$thumbnailVersion,
            'width' => (int) ($photo['width'] ?? $this->outputSize()),
            'height' => (int) ($photo['height'] ?? $this->outputSize()),
            'thumbnail_width' => (int) ($photo['thumb_width'] ?? $this->thumbnailSize()),
            'thumbnail_height' => (int) ($photo['thumb_height'] ?? $this->thumbnailSize()),
            'mime' => $this->cleanString($photo['mime'] ?? null) ?? 'image/jpeg',
            'bytes' => max(0, (int) ($photo['bytes'] ?? 0)),
            'thumbnail_bytes' => max(0, (int) ($photo['thumb_bytes'] ?? 0)),
            'version' => $version,
        ];
    }

    /**
     * Reads stored photo bytes for API streaming.
     *
     * @param  array<string, mixed>  $payload
     * @return array{binary:string,mime:string,etag:string}|null
     */
    public function readPhotoBinary(array $payload, string $variant = 'full'): ?array
    {
        $photo = $this->photoFromPayload($payload);
        if ($photo === null) {
            return null;
        }

        $normalizedVariant = strtolower(trim($variant));
        $wantsThumbnail = $normalizedVariant === 'thumb' || $normalizedVariant === 'thumbnail';
        $disk = $this->photoDiskForMeta($photo);
        $path = $wantsThumbnail
            ? $this->photoThumbnailPath($photo)
            : $this->photoPath($photo);

        if ($path === null && $wantsThumbnail) {
            $path = $this->photoPath($photo);
            $wantsThumbnail = false;
        }

        if ($path === null || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $binary = Storage::disk($disk)->get($path);
        $mime = $wantsThumbnail
            ? 'image/jpeg'
            : ($this->cleanString($photo['mime'] ?? null) ?? 'image/jpeg');
        $etag = $wantsThumbnail
            ? (
                $this->cleanString($photo['thumb_version'] ?? null)
                ?? substr((string) ($photo['thumb_sha256'] ?? sha1($binary)), 0, 16)
            )
            : (
                $this->cleanString($photo['version'] ?? null)
                ?? substr((string) ($photo['sha256'] ?? sha1($binary)), 0, 16)
            );

        return [
            'binary' => $binary,
            'mime' => $mime,
            'etag' => $etag,
        ];
    }

    /**
     * Prunes expired staged uploads.
     */
    public function pruneExpiredStagedUploads(): int
    {
        $expired = ContactPhotoUpload::query()
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', now())
            ->get();

        $deleted = 0;

        foreach ($expired as $upload) {
            $this->deleteUploadFile($upload);
            $upload->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Prunes orphaned final photo files not referenced by contacts.
     */
    public function pruneOrphanedFinalPhotos(): int
    {
        $disk = $this->photoDisk();
        $prefix = $this->finalPrefix();

        $files = Storage::disk($disk)->allFiles($prefix);
        if ($files === []) {
            return 0;
        }

        $referenced = Contact::query()
            ->orderBy('id')
            ->get(['payload'])
            ->flatMap(function (Contact $contact): array {
                $payload = is_array($contact->payload) ? $contact->payload : [];
                $photo = $this->photoFromPayload($payload);
                if ($photo === null) {
                    return [];
                }

                $disk = $this->photoDiskForMeta($photo);
                if ($disk !== $this->photoDisk()) {
                    return [];
                }

                return array_values(array_filter([
                    $this->photoPath($photo),
                    $this->photoThumbnailPath($photo),
                ]));
            })
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();

        $referencedSet = array_flip($referenced);

        $deleted = 0;
        foreach ($files as $filePath) {
            if (! isset($referencedSet[$filePath])) {
                Storage::disk($disk)->delete($filePath);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Parses raw PHOTO bytes into persisted metadata shape for CardDAV parsing.
     *
     * @param  array{binary:string,mime:string,sha256:string,width:int,height:int}  $photo
     * @return array{binary:string,mime:string,sha256:string,width:int,height:int}|null
     */
    public function normalizeParsedCardPhoto(array $photo): ?array
    {
        $this->assertImagickAvailable();

        $binary = (string) ($photo['binary'] ?? '');
        $mime = $this->cleanString($photo['mime'] ?? null);
        if ($binary === '' || $mime === null) {
            return null;
        }

        if (strlen($binary) > $this->maxUploadBytes()) {
            return null;
        }

        if (! in_array($mime, $this->allowedMimes(), true)) {
            return null;
        }

        return $this->normalizeParsedImageBinary($binary);
    }

    /**
     * Returns maximum accepted PHOTO bytes from DAV parser context.
     */
    public function maxDecodedPhotoBytes(): int
    {
        return $this->maxUploadBytes();
    }

    /**
     * Returns allowed photo mime types.
     *
     * @return array<int, string>
     */
    public function allowedMimes(): array
    {
        $configured = config('services.contacts.photo.allowed_mimes', [
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        if (! is_array($configured)) {
            return ['image/jpeg', 'image/png', 'image/webp'];
        }

        $normalized = collect($configured)
            ->map(fn (mixed $value): ?string => $this->cleanString($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->map(fn (string $value): string => strtolower($value))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * Returns photo upload constraints for API clients.
     *
     * @return array{max_upload_kb:int,min_crop_size:int,output_size:int,allowed_mimes:array<int,string>}
     */
    public function uploadConstraints(): array
    {
        return [
            'max_upload_kb' => $this->maxUploadKb(),
            'min_crop_size' => $this->minCropSize(),
            'output_size' => $this->outputSize(),
            'allowed_mimes' => $this->allowedMimes(),
        ];
    }

    /**
     * Returns configured minimum accepted crop size.
     */
    public function minCropSize(): int
    {
        return max(1, (int) config('services.contacts.photo.min_crop_size', 600));
    }

    /**
     * Returns configured output dimension.
     */
    public function outputSize(): int
    {
        return max(64, (int) config('services.contacts.photo.output_size', 1024));
    }

    /**
     * Returns configured upload max size in KB.
     */
    public function maxUploadKb(): int
    {
        return max(1, (int) config('services.contacts.photo.max_upload_kb', 8192));
    }

    /**
     * Returns photo disk.
     */
    private function photoDisk(): string
    {
        return (string) config('services.contacts.photo.disk', 'local');
    }

    /**
     * Returns stage TTL minutes.
     */
    private function stageTtlMinutes(): int
    {
        return max(1, (int) config('services.contacts.photo.stage_ttl_minutes', 10080));
    }

    /**
     * Returns max upload bytes.
     */
    private function maxUploadBytes(): int
    {
        return $this->maxUploadKb() * 1024;
    }

    /**
     * Returns staged file path.
     */
    private function stagedPath(int $userId, string $token): string
    {
        return trim($this->photoPrefix(), '/').'/staged/user-'.$userId.'/'.$token.'.jpg';
    }

    /**
     * Returns final file path.
     */
    private function finalPath(Contact $contact, string $sha256): string
    {
        return trim($this->photoPrefix(), '/').'/final/user-'.$contact->owner_id.'/contact-'.$contact->id.'/'.substr($sha256, 0, 32).'.jpg';
    }

    /**
     * Returns thumbnail file path for a final photo.
     */
    private function thumbnailPath(string $finalPath): string
    {
        $normalized = ltrim($finalPath, '/');
        $directory = trim((string) dirname($normalized), '/');
        $stem = pathinfo($normalized, PATHINFO_FILENAME);

        return $directory.'/thumb/'.$stem.'.jpg';
    }

    /**
     * Returns photo root prefix.
     */
    private function photoPrefix(): string
    {
        return (string) config('services.contacts.photo.prefix', 'contacts/photos');
    }

    /**
     * Returns final-photo prefix.
     */
    private function finalPrefix(): string
    {
        return trim($this->photoPrefix(), '/').'/final';
    }

    /**
     * Promotes staged upload to final location.
     *
     * @return array{
     *   disk:string,
     *   path:string,
     *   thumb_path:string,
     *   mime:string,
     *   width:int,
     *   height:int,
     *   thumb_width:int,
     *   thumb_height:int,
     *   bytes:int,
     *   thumb_bytes:int,
     *   sha256:string,
     *   thumb_sha256:string,
     *   version:string,
     *   thumb_version:string,
     *   updated_at:string
     * }
     */
    private function promoteUploadToFinalPhoto(ContactPhotoUpload $upload, Contact $contact): array
    {
        $disk = $upload->disk;
        $storage = Storage::disk($disk);

        if (! $storage->exists($upload->path)) {
            throw ValidationException::withMessages([
                'photo_upload_token' => ['Photo upload is no longer available.'],
            ]);
        }

        $finalPath = $this->finalPath($contact, $upload->sha256);
        $binary = $storage->get($upload->path);
        $storage->put($finalPath, $binary);
        $thumbnail = $this->createThumbnailForFinalPhoto($disk, $finalPath, $binary);
        $storage->delete($upload->path);

        $upload->consumed_at = now();
        $upload->save();

        return [
            'disk' => $disk,
            'path' => $finalPath,
            'thumb_path' => $thumbnail['path'],
            'mime' => $upload->mime,
            'width' => (int) $upload->width,
            'height' => (int) $upload->height,
            'thumb_width' => $thumbnail['width'],
            'thumb_height' => $thumbnail['height'],
            'bytes' => (int) $upload->bytes,
            'thumb_bytes' => $thumbnail['bytes'],
            'sha256' => $upload->sha256,
            'thumb_sha256' => $thumbnail['sha256'],
            'version' => substr($upload->sha256, 0, 16),
            'thumb_version' => substr($thumbnail['sha256'], 0, 16),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Stores parsed CardDAV photo content to final location.
     *
     * @param  array{binary:string,mime:string,sha256:string,width:int,height:int}  $parsedPhoto
     * @return array{
     *   disk:string,
     *   path:string,
     *   thumb_path:string,
     *   mime:string,
     *   width:int,
     *   height:int,
     *   thumb_width:int,
     *   thumb_height:int,
     *   bytes:int,
     *   thumb_bytes:int,
     *   sha256:string,
     *   thumb_sha256:string,
     *   version:string,
     *   thumb_version:string,
     *   updated_at:string
     * }|null
     */
    private function storeParsedPhoto(Contact $contact, array $parsedPhoto): ?array
    {
        $normalized = $this->normalizeParsedCardPhoto($parsedPhoto);
        if ($normalized === null) {
            return null;
        }

        $disk = $this->photoDisk();
        $path = $this->finalPath($contact, $normalized['sha256']);
        Storage::disk($disk)->put($path, $normalized['binary']);
        $thumbnail = $this->createThumbnailForFinalPhoto($disk, $path, $normalized['binary']);

        return [
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbnail['path'],
            'mime' => $normalized['mime'],
            'width' => $normalized['width'],
            'height' => $normalized['height'],
            'thumb_width' => $thumbnail['width'],
            'thumb_height' => $thumbnail['height'],
            'bytes' => strlen($normalized['binary']),
            'thumb_bytes' => $thumbnail['bytes'],
            'sha256' => $normalized['sha256'],
            'thumb_sha256' => $thumbnail['sha256'],
            'version' => substr($normalized['sha256'], 0, 16),
            'thumb_version' => substr($thumbnail['sha256'], 0, 16),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Returns active staged upload by token.
     */
    private function activeUploadByToken(
        string $token,
        ?User $actor,
        ?Contact $contact,
    ): ContactPhotoUpload {
        $query = ContactPhotoUpload::query()
            ->where('token', $token)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());

        if ($actor !== null) {
            $query->where('user_id', $actor->id);
        }

        /** @var ContactPhotoUpload|null $upload */
        $upload = $query->first();
        if (! $upload) {
            throw ValidationException::withMessages([
                'photo_upload_token' => ['Photo upload token is invalid or expired.'],
            ]);
        }

        if ($contact !== null && $upload->contact_id !== null && (int) $upload->contact_id !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'photo_upload_token' => ['Photo upload token cannot be used for this contact.'],
            ]);
        }

        return $upload;
    }

    /**
     * Normalizes an uploaded source image into canonical output bytes.
     *
     * @param  array{x:int,y:int,width:int,height:int}  $crop
     * @return array{binary:string,mime:string,width:int,height:int,bytes:int,sha256:string}
     */
    private function normalizeUploadedImage(UploadedFile $file, array $crop): array
    {
        $imagick = new Imagick;
        $imagick->readImage($file->getRealPath());

        if (method_exists($imagick, 'autoOrient')) {
            $imagick->autoOrient();
        } elseif (method_exists($imagick, 'autoOrientImage')) {
            $imagick->autoOrientImage();
        }

        $sourceWidth = (int) $imagick->getImageWidth();
        $sourceHeight = (int) $imagick->getImageHeight();
        $min = $this->minCropSize();

        if ($sourceWidth < $min || $sourceHeight < $min) {
            throw ValidationException::withMessages([
                'photo' => ['Image must be at least '.$min.'x'.$min.' pixels.'],
            ]);
        }

        $cropX = max(0, (int) ($crop['x'] ?? 0));
        $cropY = max(0, (int) ($crop['y'] ?? 0));
        $cropWidth = max(0, (int) ($crop['width'] ?? 0));
        $cropHeight = max(0, (int) ($crop['height'] ?? 0));

        if ($cropWidth < $min || $cropHeight < $min) {
            throw ValidationException::withMessages([
                'crop' => ['Crop area must be at least '.$min.'x'.$min.' pixels.'],
            ]);
        }

        if (abs($cropWidth - $cropHeight) > 2) {
            throw ValidationException::withMessages([
                'crop' => ['Crop area must use a 1:1 ratio.'],
            ]);
        }

        if (
            $cropX + $cropWidth > $sourceWidth
            || $cropY + $cropHeight > $sourceHeight
        ) {
            throw ValidationException::withMessages([
                'crop' => ['Crop area is outside of the image boundaries.'],
            ]);
        }

        $imagick->cropImage($cropWidth, $cropHeight, $cropX, $cropY);
        $imagick->setImagePage(0, 0, 0, 0);
        $normalized = $this->renderFinalImageBlob($imagick);
        $imagick->clear();
        $imagick->destroy();

        return $normalized;
    }

    /**
     * Normalizes CardDAV PHOTO bytes into canonical output bytes.
     *
     * @return array{binary:string,mime:string,sha256:string,width:int,height:int}|null
     */
    private function normalizeParsedImageBinary(string $binary): ?array
    {
        $imagick = new Imagick;
        try {
            $imagick->readImageBlob($binary);
        } catch (\Throwable) {
            $imagick->clear();
            $imagick->destroy();

            return null;
        }

        if (method_exists($imagick, 'autoOrient')) {
            $imagick->autoOrient();
        } elseif (method_exists($imagick, 'autoOrientImage')) {
            $imagick->autoOrientImage();
        }

        $width = (int) $imagick->getImageWidth();
        $height = (int) $imagick->getImageHeight();
        $min = $this->minCropSize();

        if ($width < $min || $height < $min) {
            $imagick->clear();
            $imagick->destroy();

            return null;
        }

        $square = min($width, $height);
        $offsetX = max(0, (int) floor(($width - $square) / 2));
        $offsetY = max(0, (int) floor(($height - $square) / 2));

        $imagick->cropImage($square, $square, $offsetX, $offsetY);
        $imagick->setImagePage(0, 0, 0, 0);

        $normalized = $this->renderFinalImageBlob($imagick);
        $imagick->clear();
        $imagick->destroy();

        return [
            'binary' => $normalized['binary'],
            'mime' => $normalized['mime'],
            'sha256' => $normalized['sha256'],
            'width' => $normalized['width'],
            'height' => $normalized['height'],
        ];
    }

    /**
     * Renders canonical JPEG output for stored contact photos.
     *
     * @return array{binary:string,mime:string,width:int,height:int,bytes:int,sha256:string}
     */
    private function renderFinalImageBlob(Imagick $imagick): array
    {
        $outputSize = $this->outputSize();
        $imagick->resizeImage($outputSize, $outputSize, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->stripImage();
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
        $imagick->setImageCompressionQuality($this->jpegQuality());
        $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);

        $binary = (string) $imagick->getImageBlob();
        if ($binary === '') {
            throw new RuntimeException('Unable to normalize contact photo.');
        }

        return [
            'binary' => $binary,
            'mime' => 'image/jpeg',
            'width' => $outputSize,
            'height' => $outputSize,
            'bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
        ];
    }

    /**
     * Renders and stores a thumbnail next to the final photo.
     *
     * @return array{path:string,width:int,height:int,bytes:int,sha256:string}
     */
    private function createThumbnailForFinalPhoto(string $disk, string $finalPath, string $sourceBinary): array
    {
        $imagick = new Imagick;
        $imagick->readImageBlob($sourceBinary);

        $size = $this->thumbnailSize();
        $imagick->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->stripImage();
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
        $imagick->setImageCompressionQuality($this->thumbnailJpegQuality());
        $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);

        $binary = (string) $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        if ($binary === '') {
            throw new RuntimeException('Unable to generate contact photo thumbnail.');
        }

        $path = $this->thumbnailPath($finalPath);
        Storage::disk($disk)->put($path, $binary);

        return [
            'path' => $path,
            'width' => $size,
            'height' => $size,
            'bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
        ];
    }

    /**
     * Returns JPEG quality.
     */
    private function jpegQuality(): int
    {
        return max(1, min(100, (int) config('services.contacts.photo.jpeg_quality', 82)));
    }

    /**
     * Returns output thumbnail dimension.
     */
    private function thumbnailSize(): int
    {
        return max(48, min(512, (int) config('services.contacts.photo.thumbnail_size', 192)));
    }

    /**
     * Returns thumbnail JPEG quality.
     */
    private function thumbnailJpegQuality(): int
    {
        return max(1, min(100, (int) config('services.contacts.photo.thumbnail_quality', 74)));
    }

    /**
     * Asserts mime type allowed.
     */
    private function assertMimeAllowed(UploadedFile $file): void
    {
        $mime = strtolower((string) $file->getMimeType());
        if (in_array($mime, $this->allowedMimes(), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'photo' => ['Unsupported photo format.'],
        ]);
    }

    /**
     * Deletes staged file for an upload row.
     */
    private function deleteUploadFile(ContactPhotoUpload $upload): void
    {
        Storage::disk($upload->disk)->delete($upload->path);
    }

    /**
     * Returns normalized photo metadata from payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function photoFromPayload(array $payload): ?array
    {
        $photo = $payload['photo'] ?? null;
        if (! is_array($photo)) {
            return null;
        }

        $path = $this->photoPath($photo);
        $disk = $this->photoDiskForMeta($photo);
        $mime = $this->cleanString($photo['mime'] ?? null);

        if ($path === null || $disk === '' || $mime === null) {
            return null;
        }

        return $photo;
    }

    /**
     * Returns photo path from metadata.
     *
     * @param  array<string, mixed>  $photo
     */
    private function photoPath(array $photo): ?string
    {
        $path = $this->cleanString($photo['path'] ?? null);
        if ($path === null) {
            return null;
        }

        return ltrim($path, '/');
    }

    /**
     * Returns thumbnail path from metadata.
     *
     * @param  array<string, mixed>  $photo
     */
    private function photoThumbnailPath(array $photo): ?string
    {
        $path = $this->cleanString($photo['thumb_path'] ?? null);
        if ($path !== null) {
            return ltrim($path, '/');
        }

        $mainPath = $this->photoPath($photo);
        if ($mainPath === null) {
            return null;
        }

        return $this->thumbnailPath($mainPath);
    }

    /**
     * Returns disk from photo metadata.
     *
     * @param  array<string, mixed>  $photo
     */
    private function photoDiskForMeta(array $photo): string
    {
        return $this->cleanString($photo['disk'] ?? null) ?? $this->photoDisk();
    }

    /**
     * Deletes a persisted photo file.
     *
     * @param  array<string, mixed>  $photo
     */
    private function deletePhotoFile(array $photo): void
    {
        $disk = $this->photoDiskForMeta($photo);
        $path = $this->photoPath($photo);
        $thumbnailPath = $this->photoThumbnailPath($photo);

        if ($path === null && $thumbnailPath === null) {
            return;
        }

        Storage::disk($disk)->delete(array_values(array_filter([$path, $thumbnailPath])));
    }

    /**
     * Returns clean string.
     */
    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Ensures Imagick is available.
     */
    private function assertImagickAvailable(): void
    {
        if (class_exists(Imagick::class)) {
            return;
        }

        throw new RuntimeException('Imagick extension is required for managed contact photos.');
    }
}
