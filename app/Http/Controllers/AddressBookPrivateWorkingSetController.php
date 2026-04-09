<?php

namespace App\Http\Controllers;

use App\Models\AddressBookPrivateWorkingSetLink;
use App\Models\Card;
use App\Services\AddressBookPrivateWorkingSetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressBookPrivateWorkingSetController extends Controller
{
    public function __construct(
        private readonly AddressBookPrivateWorkingSetService $privateWorkingSetService,
    ) {}

    /**
     * Updates private working-set config.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'hide_shared' => ['sometimes', 'boolean'],
            'source_ids' => ['array'],
            'source_ids.*' => ['integer', 'min:1'],
        ]);

        $privateWorkingSet = $this->privateWorkingSetService->updateUserConfig(
            user: $request->user(),
            enabled: (bool) $data['enabled'],
            hideShared: (bool) ($data['hide_shared'] ?? true),
            sourceIds: $data['source_ids'] ?? [],
        );

        return response()->json([
            'private_working_set' => $privateWorkingSet,
        ]);
    }

    /**
     * Pulls latest shared source values into private working set.
     */
    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'force_server' => ['sometimes', 'boolean'],
        ]);

        $result = $this->privateWorkingSetService->pullLatest(
            user: $request->user(),
            forceServer: (bool) ($data['force_server'] ?? false),
        );

        return response()->json([
            'private_working_set_pull' => $result,
        ]);
    }

    /**
     * Promotes private card to shared source card.
     */
    public function promote(Request $request, Card $card): JsonResponse
    {
        $result = $this->privateWorkingSetService->promotePrivateCard(
            actor: $request->user(),
            privateCard: $card,
        );

        if (($result['queued'] ?? false) === true) {
            return response()->json([
                'queued' => true,
                'message' => __('contacts.change_submitted_for_owner_or_admin_approval'),
                'group_uuid' => $result['group_uuid'] ?? null,
                'request_ids' => $result['request_ids'] ?? [],
            ], 202);
        }

        return response()->json([
            'queued' => false,
            'applied' => true,
            'source_address_book_id' => $result['source_address_book_id'] ?? null,
            'source_card_uri' => $result['source_card_uri'] ?? null,
        ]);
    }

    /**
     * Dismisses one suggested private-card promotion.
     */
    public function dismissSuggestion(
        Request $request,
        AddressBookPrivateWorkingSetLink $link,
    ): JsonResponse {
        $result = $this->privateWorkingSetService->dismissSuggestedPromotion(
            actor: $request->user(),
            link: $link,
        );

        return response()->json([
            'suggested_promotion_dismissed' => $result,
        ]);
    }
}
