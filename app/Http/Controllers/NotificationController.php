<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationCountService;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationCountService $counts,
    ) {}

    public function webPush(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $this->preferences->isWebPushEnabled(),
            'available' => $this->preferences->isAvailable(),
            'public_key' => $this->preferences->isAvailable() ? $this->preferences->publicKey() : null,
            'preferences' => $this->preferences->preferencesFor($user),
            'counts' => $this->counts->countsFor($user),
            'subscription_count' => $user->pushSubscriptions()->count(),
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        return response()->json($this->counts->countsFor($request->user()));
    }

    public function updateWebPushPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'review_queue_enabled' => ['sometimes', 'boolean'],
            'admin_pending_registration_enabled' => ['sometimes', 'boolean'],
            'admin_backup_operations_enabled' => ['sometimes', 'boolean'],
        ]);

        if (! $request->user()->isAdmin()) {
            foreach (['admin_pending_registration_enabled', 'admin_backup_operations_enabled'] as $key) {
                if (array_key_exists($key, $data)) {
                    abort(403, 'Only admins can manage admin notification categories.');
                }
            }
        }

        return response()->json([
            'preferences' => $this->preferences->update($request->user(), $data),
        ]);
    }

    public function storeWebPushSubscription(Request $request): JsonResponse
    {
        abort_unless($this->preferences->isAvailable(), 404);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $request->user()->updatePushSubscription(
            endpoint: $data['endpoint'],
            key: $data['keys']['p256dh'],
            token: $data['keys']['auth'],
            contentEncoding: $data['content_encoding'] ?? 'aes128gcm',
        );

        $this->preferences->ensureDefaultsFor($request->user());

        return response()->json([
            'ok' => true,
            'preferences' => $this->preferences->preferencesFor($request->user()),
            'subscription_count' => $request->user()->pushSubscriptions()->count(),
        ], 201);
    }

    public function destroyWebPushSubscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json([
            'ok' => true,
            'subscription_count' => $request->user()->pushSubscriptions()->count(),
        ]);
    }
}
