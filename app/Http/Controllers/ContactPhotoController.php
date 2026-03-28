<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\Contacts\ContactPhotoService;
use App\Services\Contacts\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactPhotoController extends Controller
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly ContactPhotoService $contactPhotoService,
    ) {}

    /**
     * Stages a cropped photo upload for a new contact draft.
     */
    public function stage(Request $request): JsonResponse
    {
        return $this->stageFor($request, null);
    }

    /**
     * Stages a cropped photo upload for an existing contact.
     */
    public function stageForContact(Request $request, int $contact): JsonResponse
    {
        $model = Contact::query()->findOrFail($contact);
        $this->assertCanWriteContact($request, $model);

        return $this->stageFor($request, $model);
    }

    /**
     * Streams the persisted contact photo.
     */
    public function show(Request $request, int $contact)
    {
        $model = Contact::query()->findOrFail($contact);
        $this->assertCanWriteContact($request, $model);

        $payload = is_array($model->payload) ? $model->payload : [];
        $photo = $this->contactPhotoService->readPhotoBinary($payload);
        if ($photo === null) {
            abort(404);
        }

        $etag = '"'.$photo['etag'].'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($photo['binary'], 200, [
            'Content-Type' => $photo['mime'],
            'Content-Length' => (string) strlen($photo['binary']),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => $etag,
        ]);
    }

    /**
     * Performs staged upload flow.
     */
    private function stageFor(Request $request, ?Contact $contact): JsonResponse
    {
        $mimes = implode(',', $this->contactPhotoService->allowedMimes());
        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'mimetypes:'.$mimes,
                'max:'.$this->contactPhotoService->maxUploadKb(),
            ],
            'crop_x' => ['required', 'integer', 'min:0'],
            'crop_y' => ['required', 'integer', 'min:0'],
            'crop_width' => ['required', 'integer', 'min:1'],
            'crop_height' => ['required', 'integer', 'min:1'],
        ]);

        $upload = $this->contactPhotoService->stageUpload(
            actor: $request->user(),
            file: $validated['photo'],
            crop: [
                'x' => (int) $validated['crop_x'],
                'y' => (int) $validated['crop_y'],
                'width' => (int) $validated['crop_width'],
                'height' => (int) $validated['crop_height'],
            ],
            contact: $contact,
        );

        return response()->json([
            'token' => $upload->token,
            'mime' => $upload->mime,
            'width' => (int) $upload->width,
            'height' => (int) $upload->height,
            'bytes' => (int) $upload->bytes,
            'expires_at' => $upload->expires_at?->toIso8601String(),
            'constraints' => $this->contactPhotoService->uploadConstraints(),
        ], 201);
    }

    /**
     * Enforces writable access for managed contact mutations.
     */
    private function assertCanWriteContact(Request $request, Contact $contact): void
    {
        if ($this->contactService->canUserWriteContact($request->user(), $contact)) {
            return;
        }

        abort(403, __('contacts.cannot_modify_contact'));
    }
}

