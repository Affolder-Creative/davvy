<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\AddressBookPrivateWorkingSetConfig;
use App\Models\AddressBookPrivateWorkingSetLink;
use App\Models\Card;
use App\Models\Contact;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\Contacts\ContactChangeRequestService;
use App\Services\Contacts\ContactVCardService;
use App\Services\Contacts\ManagedContactSyncService;
use App\Services\Dav\DavSyncService;
use App\Services\Dav\VCardValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\NotFound;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;

class AddressBookPrivateWorkingSetService
{
    private const PRIVATE_SOURCE_PROPERTY = 'X-DAVVY-PRIVATE-SOURCE';

    private const PRIVATE_OWNER_PROPERTY = 'X-DAVVY-PRIVATE-OWNER';

    /**
     * @var array<int, string>
     */
    private const OVERRIDABLE_PAYLOAD_FIELDS = [
        'prefix',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'nickname',
        'company',
        'job_title',
        'department',
        'pronouns',
        'pronouns_custom',
        'ringtone',
        'text_tone',
        'phonetic_first_name',
        'phonetic_last_name',
        'phonetic_company',
        'maiden_name',
        'verification_code',
        'profile',
        'notes',
        'head_of_household',
        'exclude_milestone_calendars',
        'categories',
        'birthday',
        'phones',
        'emails',
        'urls',
        'addresses',
        'dates',
        'related_names',
        'instant_messages',
        'photo',
    ];

    /**
     * @var array<int, string>
     */
    private const SUGGESTABLE_PROMOTION_FIELDS = [
        'prefix',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'nickname',
        'company',
        'job_title',
        'department',
        'birthday',
        'phones',
        'emails',
        'urls',
        'addresses',
    ];

    public function __construct(
        private readonly DavSyncService $syncService,
        private readonly ResourceAccessService $accessService,
        private readonly VCardValidator $vCardValidator,
        private readonly ContactVCardService $vCardService,
        private readonly ManagedContactSyncService $managedContactSync,
        private readonly AddressBookMirrorService $mirrorService,
        private readonly RegistrationSettingsService $settingsService,
    ) {}

    /**
     * Checks whether private working set features are globally enabled.
     */
    public function isEnabledGlobally(): bool
    {
        return $this->settingsService->isPrivateWorkingSetEnabled();
    }

    /**
     * Returns dashboard data.
     */
    public function dashboardDataFor(User $user): array
    {
        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        if (! $this->isEnabledGlobally()) {
            $includeOwnedSharableSources = $this->resolveIncludeOwnedSharableSources($user, $config);
            $requireReviewForSelfPromotions = $this->resolveRequireReviewForSelfPromotions($user, $config);

            return [
                'enabled' => false,
                'hide_shared' => (bool) ($config?->hide_shared ?? true),
                'include_owned_sharable_sources' => $includeOwnedSharableSources,
                'require_review_for_self_promotions' => $requireReviewForSelfPromotions,
                'can_manage_self_review_policy' => $user->isAdmin(),
                'effective_require_review_for_self_promotions' => false,
                'private_address_book_id' => null,
                'private_address_book_uri' => null,
                'private_display_name' => null,
                'selected_source_ids' => [],
                'source_options' => [],
                'linked_cards' => [],
                'suggested_promotions' => [],
            ];
        }

        $includeOwnedSharableSources = $this->resolveIncludeOwnedSharableSources($user, $config);
        $requireReviewForSelfPromotions = $this->resolveRequireReviewForSelfPromotions($user, $config);
        $effectiveRequireReviewForSelfPromotions = $this->effectiveRequireReviewForSelfPromotions(
            $user,
            $requireReviewForSelfPromotions,
        );
        $privateAddressBook = $this->resolvePrivateAddressBook($user, $config);
        $sourceOptions = $this->eligibleSharedSourceOptionsForUser(
            user: $user,
            includeOwnedSharableSources: $includeOwnedSharableSources,
        );
        $optionIds = $sourceOptions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $selected = collect($config?->sources ?? [])
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->intersect($optionIds)
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($config?->enabled ?? false),
            'hide_shared' => (bool) ($config?->hide_shared ?? true),
            'include_owned_sharable_sources' => $includeOwnedSharableSources,
            'require_review_for_self_promotions' => $requireReviewForSelfPromotions,
            'can_manage_self_review_policy' => $user->isAdmin(),
            'effective_require_review_for_self_promotions' => $effectiveRequireReviewForSelfPromotions,
            'private_address_book_id' => $privateAddressBook?->id,
            'private_address_book_uri' => $privateAddressBook?->uri,
            'private_display_name' => $privateAddressBook?->display_name,
            'selected_source_ids' => $selected,
            'source_options' => $sourceOptions->all(),
            'linked_cards' => $this->dashboardLinkedCardsFor($user),
            'suggested_promotions' => $this->dashboardSuggestedPromotionsFor($user),
        ];
    }

    /**
     * Updates user config.
     */
    public function updateUserConfig(
        User $user,
        bool $enabled,
        bool $hideShared,
        array $sourceIds,
        ?bool $includeOwnedSharableSources = null,
        ?bool $requireReviewForSelfPromotions = null,
    ): array {
        if (! $this->isEnabledGlobally()) {
            abort(403, __('contacts.private_working_set_disabled_by_admins'));
        }

        $existingConfig = AddressBookPrivateWorkingSetConfig::query()
            ->where('user_id', $user->id)
            ->first();

        $resolvedIncludeOwnedSharableSources = $includeOwnedSharableSources
            ?? $this->resolveIncludeOwnedSharableSources($user, $existingConfig);
        $resolvedRequireReviewForSelfPromotions = $requireReviewForSelfPromotions
            ?? $this->resolveRequireReviewForSelfPromotions($user, $existingConfig);

        if (
            ! $user->isAdmin()
            && $this->settingsService->isContactChangeModerationEnabled()
        ) {
            // Non-admin promotions are always reviewed while moderation is enabled.
            $resolvedRequireReviewForSelfPromotions = true;
        }

        $sourceOptions = $this->eligibleSharedSourceOptionsForUser(
            user: $user,
            includeOwnedSharableSources: $resolvedIncludeOwnedSharableSources,
        );
        $sourceOptionIds = $sourceOptions->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $sanitizedSourceIds = collect($sourceIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($sanitizedSourceIds as $sourceId) {
            if (! in_array($sourceId, $sourceOptionIds, true)) {
                abort(422, __('contacts.selected_address_books_not_eligible_for_private_working_set'));
            }
        }

        $config = AddressBookPrivateWorkingSetConfig::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => $enabled,
                'hide_shared' => $hideShared,
                'include_owned_sharable_sources' => $resolvedIncludeOwnedSharableSources,
                'require_review_for_self_promotions' => $resolvedRequireReviewForSelfPromotions,
            ],
        );

        if ($enabled) {
            $privateAddressBook = $this->ensurePrivateAddressBook($user, $config);
            if ((int) ($config->private_address_book_id ?? 0) !== (int) $privateAddressBook->id) {
                $config->private_address_book_id = $privateAddressBook->id;
            }
        }

        $config->enabled = $enabled;
        $config->hide_shared = $hideShared;
        $config->include_owned_sharable_sources = $resolvedIncludeOwnedSharableSources;
        $config->require_review_for_self_promotions = $resolvedRequireReviewForSelfPromotions;
        $config->save();

        $config->sources()
            ->whereNotIn('source_address_book_id', $sanitizedSourceIds)
            ->delete();

        $existing = $config->sources()
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_diff($sanitizedSourceIds, $existing) as $sourceId) {
            $config->sources()->create([
                'source_address_book_id' => $sourceId,
            ]);
        }

        $this->syncUserConfig($user);

        return $this->dashboardDataFor($user);
    }

    /**
     * Synchronizes user config.
     */
    public function syncUserConfig(User $user, bool $forceServer = false): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        if (! $config) {
            return;
        }

        $includeOwnedSharableSources = $this->resolveIncludeOwnedSharableSources($user, $config);
        $privateAddressBook = $this->resolvePrivateAddressBook($user, $config);
        $selectedIds = collect($config->sources)
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(
                fn (int $id): bool => $this->userCanUseSourceAddressBook(
                    user: $user,
                    sourceAddressBookId: $id,
                    includeOwnedSharableSources: $includeOwnedSharableSources,
                )
            )
            ->unique()
            ->values()
            ->all();

        if (! $config->enabled || $selectedIds === []) {
            $this->removePrivateLinksForUser($user->id);

            return;
        }

        if (! $privateAddressBook) {
            $privateAddressBook = $this->ensurePrivateAddressBook($user, $config);
        }

        $this->removePrivateLinksForUser(
            userId: $user->id,
            exceptSourceAddressBookIds: $selectedIds,
        );

        foreach ($selectedIds as $sourceAddressBookId) {
            $this->syncSourceAddressBookForUser(
                user: $user,
                privateAddressBook: $privateAddressBook,
                sourceAddressBookId: $sourceAddressBookId,
                includeOwnedSharableSources: $includeOwnedSharableSources,
                forceServer: $forceServer,
            );
        }
    }

    /**
     * Pulls latest source changes into private working set.
     */
    public function pullLatest(User $user, bool $forceServer = false): array
    {
        if (! $this->isEnabledGlobally()) {
            return [
                'ok' => true,
                'force_server' => $forceServer,
            ];
        }

        $this->syncUserConfig($user, forceServer: $forceServer);

        return [
            'ok' => true,
            'force_server' => $forceServer,
        ];
    }

    /**
     * Promotes private linked card into its source card.
     *
     * @return array<string, mixed>
     */
    public function promotePrivateCard(User $actor, Card $privateCard): array
    {
        if (! $this->isEnabledGlobally()) {
            abort(403, __('contacts.private_working_set_disabled_by_admins'));
        }

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $link) {
            abort(403, __('contacts.cannot_modify_private_working_set_card'));
        }

        $sourceAddressBook = AddressBook::query()->find($link->source_address_book_id);
        $sourceCard = Card::query()
            ->where('address_book_id', $link->source_address_book_id)
            ->where('uri', $link->source_card_uri)
            ->first();

        if (! $sourceAddressBook || ! $sourceCard) {
            $this->deletePrivateLink($link);
            throw new NotFound(__('contacts.source_contact_no_longer_exists'));
        }

        if (! $this->accessService->userCanWriteAddressBook($actor, $sourceAddressBook)) {
            abort(403, __('contacts.write_access_denied_for_private_working_set_source_address_book'));
        }

        $sourceUid = trim((string) $sourceCard->uid);
        if ($sourceUid === '') {
            $sourceUid = 'legacy-card-'.sha1($link->source_card_uri);
        }

        $normalized = $this->vCardValidator->validateAndNormalize(
            $this->sourcePayloadFromPrivateUpdate($privateCard->data, $sourceUid),
        );

        $config = AddressBookPrivateWorkingSetConfig::query()
            ->where('user_id', $actor->id)
            ->first();
        $forcedQueueOwnerIds = $this->forcedQueueOwnerIdsForPromotion(
            actor: $actor,
            sourceAddressBook: $sourceAddressBook,
            config: $config,
        );

        $queued = $this->contactChangeRequestService()->enqueueCardDavUpdateIfNeeded(
            actor: $actor,
            addressBook: $sourceAddressBook,
            card: $sourceCard,
            normalizedCardData: $normalized['data'],
            forcedQueueOwnerIds: $forcedQueueOwnerIds,
        );

        if ($queued !== null) {
            $this->dismissCurrentSuggestion($link);

            return [
                'queued' => true,
                'group_uuid' => $queued['group_uuid'],
                'request_ids' => $queued['request_ids'],
                'owner_ids' => $queued['owner_ids'],
            ];
        }

        $resourceUid = $normalized['uid'] ?? $sourceUid;
        if ($this->uidConflictExists($sourceAddressBook->id, $resourceUid, $sourceCard->id)) {
            throw new Conflict(__('dav.contact_with_same_uid_exists_in_address_book'));
        }

        $normalizedData = $normalized['data'];
        $size = strlen($normalizedData);
        $etag = md5($normalizedData);
        $isNoOp = $sourceCard->uid === $resourceUid
            && $sourceCard->etag === $etag
            && (int) $sourceCard->size === $size
            && $sourceCard->data === $normalizedData;

        if (! $isNoOp) {
            $sourceCard->update([
                'uid' => $resourceUid,
                'etag' => $etag,
                'size' => $size,
                'data' => $normalizedData,
            ]);

            $this->syncService->recordModified(
                resourceType: ShareResourceType::AddressBook,
                resourceId: $sourceAddressBook->id,
                uri: $sourceCard->uri,
            );

            $sourceCard->fill([
                'uid' => $resourceUid,
                'etag' => $etag,
                'size' => $size,
                'data' => $normalizedData,
            ]);
            try {
                $this->managedContactSync->syncCardUpsert(
                    addressBook: $sourceAddressBook,
                    card: $sourceCard,
                    actor: $actor,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
            $this->mirrorService->handleSourceCardUpsert($sourceAddressBook, $sourceCard);
            $this->handleSourceCardUpsert($sourceAddressBook, $sourceCard);
        }

        $this->dismissCurrentSuggestion($link);

        return [
            'queued' => false,
            'applied' => true,
            'source_address_book_id' => $sourceAddressBook->id,
            'source_card_uri' => $sourceCard->uri,
            'source_card_etag' => $sourceCard->etag,
        ];
    }

    /**
     * Dismisses one suggested promotion for actor.
     *
     * @return array<string, mixed>
     */
    public function dismissSuggestedPromotion(User $actor, AddressBookPrivateWorkingSetLink $link): array
    {
        if (! $this->isEnabledGlobally()) {
            abort(403, __('contacts.private_working_set_disabled_by_admins'));
        }

        if ((int) $link->user_id !== (int) $actor->id) {
            abort(403, __('contacts.cannot_modify_private_working_set_card'));
        }

        $state = $this->suggestionStateForLink($link);
        if (! is_array($state)) {
            $link->update([
                'dismissed_suggestion_fingerprint' => null,
                'dismissed_suggestion_at' => now(),
            ]);

            return [
                'dismissed' => false,
                'fingerprint' => null,
            ];
        }

        $fingerprint = (string) ($state['fingerprint'] ?? '');
        if ($fingerprint === '') {
            return [
                'dismissed' => false,
                'fingerprint' => null,
            ];
        }

        $link->update([
            'dismissed_suggestion_fingerprint' => $fingerprint,
            'dismissed_suggestion_at' => now(),
        ]);

        return [
            'dismissed' => true,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Returns source address book IDs hidden from CardDAV discovery for the user.
     *
     * @return array<int, int>
     */
    public function hiddenSourceAddressBookIdsForUser(User $user): array
    {
        if (! $this->isEnabledGlobally()) {
            return [];
        }

        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        if (! $config || ! $config->enabled || ! $config->hide_shared) {
            return [];
        }

        $includeOwnedSharableSources = $this->resolveIncludeOwnedSharableSources($user, $config);

        return collect($config->sources)
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(
                fn (int $id): bool => $id > 0 && $this->userCanUseSourceAddressBook(
                    user: $user,
                    sourceAddressBookId: $id,
                    includeOwnedSharableSources: $includeOwnedSharableSources,
                )
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Checks whether source address book is hidden for user.
     */
    public function isSharedSourceHiddenForUser(User $user, int $addressBookId): bool
    {
        return in_array($addressBookId, $this->hiddenSourceAddressBookIdsForUser($user), true);
    }

    /**
     * Returns private working-set address books that are quarantined for the user.
     *
     * @return array<int, int>
     */
    public function quarantinedPrivateAddressBookIdsForUser(User $user): array
    {
        if ($this->isEnabledGlobally()) {
            return [];
        }

        $config = AddressBookPrivateWorkingSetConfig::query()
            ->where('user_id', $user->id)
            ->first();
        $resolvedPrivateAddressBookId = $this->resolvePrivateAddressBook($user, $config)?->id;

        return collect([
            $config?->private_address_book_id,
            $resolvedPrivateAddressBookId,
        ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Checks whether a private working-set address book is quarantined for the user.
     */
    public function isQuarantinedPrivateAddressBookForUser(User $user, int $addressBookId): bool
    {
        return in_array($addressBookId, $this->quarantinedPrivateAddressBookIdsForUser($user), true);
    }

    /**
     * Returns private address book ID for user.
     */
    public function privateAddressBookIdForUser(User $user): ?int
    {
        $config = AddressBookPrivateWorkingSetConfig::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $config) {
            return null;
        }

        return $this->resolvePrivateAddressBook($user, $config)?->id;
    }

    /**
     * Checks whether this address book is managed by private working set.
     */
    public function isPrivateAddressBook(int $addressBookId): bool
    {
        return AddressBookPrivateWorkingSetConfig::query()
            ->where('private_address_book_id', $addressBookId)
            ->exists();
    }

    /**
     * Checks whether card payload is private working-set managed.
     */
    public function isPrivateManagedCard(string $cardData): bool
    {
        return stripos($cardData, self::PRIVATE_SOURCE_PROPERTY.':') !== false;
    }

    /**
     * Handles source card upsert.
     */
    public function handleSourceCardUpsert(AddressBook $sourceAddressBook, Card $sourceCard): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        if ($this->isPrivateManagedCard($sourceCard->data)) {
            return;
        }

        $configs = AddressBookPrivateWorkingSetConfig::query()
            ->with(['user', 'sources'])
            ->where('enabled', true)
            ->whereHas('sources', fn ($query) => $query->where('source_address_book_id', $sourceAddressBook->id))
            ->get();

        foreach ($configs as $config) {
            $user = $config->user;
            if (! $user) {
                continue;
            }

            $privateAddressBook = $this->resolvePrivateAddressBook($user, $config);
            if (! $privateAddressBook || $privateAddressBook->id === $sourceAddressBook->id) {
                continue;
            }

            if (! $this->userCanUseSourceAddressBook($user, $sourceAddressBook->id)) {
                $this->removePrivateLinksForUser(
                    userId: $user->id,
                    sourceAddressBookIds: [$sourceAddressBook->id],
                );

                continue;
            }

            $this->upsertPrivateCard(
                user: $user,
                privateAddressBook: $privateAddressBook,
                sourceAddressBook: $sourceAddressBook,
                sourceCard: $sourceCard,
                forceServer: false,
            );
        }
    }

    /**
     * Handles source card deleted.
     */
    public function handleSourceCardDeleted(int $sourceAddressBookId, string $sourceCardUri): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        $links = AddressBookPrivateWorkingSetLink::query()
            ->where('source_address_book_id', $sourceAddressBookId)
            ->where('source_card_uri', $sourceCardUri)
            ->get();

        foreach ($links as $link) {
            $this->deletePrivateLink($link);
        }
    }

    /**
     * Handles source address book deleted.
     */
    public function handleSourceAddressBookDeleted(int $sourceAddressBookId): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        $links = AddressBookPrivateWorkingSetLink::query()
            ->where('source_address_book_id', $sourceAddressBookId)
            ->get();

        foreach ($links as $link) {
            $this->deletePrivateLink($link);
        }
    }

    /**
     * Handles private card update and tracks overridden fields.
     */
    public function handlePrivateCardUpsert(Card $privateCard): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->first();

        if (! $link) {
            return;
        }

        $privateParsed = $this->vCardService->parse($privateCard->data);
        if (! is_array($privateParsed) || ! is_array($privateParsed['payload'] ?? null)) {
            return;
        }

        $sourcePayload = is_array($link->source_payload) ? $link->source_payload : null;
        $sourceCardData = null;
        if ($sourcePayload === null) {
            $sourceCard = Card::query()
                ->where('address_book_id', $link->source_address_book_id)
                ->where('uri', $link->source_card_uri)
                ->first();
            if ($sourceCard) {
                $sourceCardData = $sourceCard->data;
                $sourceParsed = $this->vCardService->parse($sourceCard->data);
                if (is_array($sourceParsed) && is_array($sourceParsed['payload'] ?? null)) {
                    $sourcePayload = $sourceParsed['payload'];
                }
            }
        } else {
            $sourceCard = Card::query()
                ->where('address_book_id', $link->source_address_book_id)
                ->where('uri', $link->source_card_uri)
                ->first();
            $sourceCardData = $sourceCard?->data;
        }

        if (! is_array($sourcePayload)) {
            return;
        }

        $overriddenFields = $this->overriddenFieldsForPayload(
            sourcePayload: $sourcePayload,
            privatePayload: $privateParsed['payload'],
            sourceCardData: $sourceCardData,
            privateCardData: $privateCard->data,
        );

        $link->update([
            'source_payload' => $sourcePayload,
            'overridden_fields' => $overriddenFields,
        ]);
    }

    /**
     * Handles private card deleted.
     */
    public function handlePrivateCardDeleted(Card $privateCard): void
    {
        if (! $this->isEnabledGlobally()) {
            return;
        }

        AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->delete();
    }

    /**
     * Returns eligible source options for user.
     *
     * @return Collection<int, array{id:int,uri:string,display_name:string,scope:string,owner_name:?string,owner_email:?string,permission:string,can_write:bool}>
     */
    private function eligibleSharedSourceOptionsForUser(
        User $user,
        bool $includeOwnedSharableSources,
    ): Collection {
        $shared = ResourceShare::query()
            ->with(['addressBook', 'owner'])
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('shared_with_id', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (ResourceShare $share): bool => $share->addressBook !== null)
            ->map(function (ResourceShare $share): array {
                return [
                    'id' => $share->addressBook->id,
                    'uri' => $share->addressBook->uri,
                    'display_name' => $share->addressBook->display_name,
                    'scope' => 'shared',
                    'owner_name' => $share->owner?->name,
                    'owner_email' => $share->owner?->email,
                    'permission' => $share->permission->value,
                    'can_write' => $share->permission->canWrite(),
                ];
            });

        $owned = collect();
        if ($includeOwnedSharableSources) {
            $owned = AddressBook::query()
                ->where('owner_id', $user->id)
                ->where('is_sharable', true)
                ->orderBy('id')
                ->get()
                ->map(fn (AddressBook $addressBook): array => [
                    'id' => $addressBook->id,
                    'uri' => $addressBook->uri,
                    'display_name' => $addressBook->display_name,
                    'scope' => 'owned',
                    'owner_name' => $user->name,
                    'owner_email' => $user->email,
                    'permission' => 'admin',
                    'can_write' => true,
                ]);
        }

        return $shared
            ->concat($owned)
            ->unique('id')
            ->sortBy(fn (array $item): string => mb_strtolower($item['display_name']))
            ->values();
    }

    /**
     * Returns default include-owned setting.
     */
    private function defaultIncludeOwnedSharableSources(): bool
    {
        return true;
    }

    /**
     * Returns default self-review setting.
     */
    private function defaultRequireReviewForSelfPromotions(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Resolves include-owned setting from config/default.
     */
    private function resolveIncludeOwnedSharableSources(
        User $user,
        ?AddressBookPrivateWorkingSetConfig $config,
    ): bool {
        if ($config !== null && $config->include_owned_sharable_sources !== null) {
            return (bool) $config->include_owned_sharable_sources;
        }

        return $this->defaultIncludeOwnedSharableSources();
    }

    /**
     * Resolves self-review setting from config/default.
     */
    private function resolveRequireReviewForSelfPromotions(
        User $user,
        ?AddressBookPrivateWorkingSetConfig $config,
    ): bool {
        if ($config !== null && $config->require_review_for_self_promotions !== null) {
            return (bool) $config->require_review_for_self_promotions;
        }

        return $this->defaultRequireReviewForSelfPromotions($user);
    }

    /**
     * Resolves the effective self-review behavior.
     */
    private function effectiveRequireReviewForSelfPromotions(User $user, bool $resolvedSetting): bool
    {
        if (! $this->settingsService->isContactChangeModerationEnabled()) {
            return false;
        }

        if (! $user->isAdmin()) {
            return true;
        }

        return $resolvedSetting;
    }

    /**
     * Returns forced queue owner IDs for promotion when self-review is enabled.
     *
     * @return array<int, int>
     */
    private function forcedQueueOwnerIdsForPromotion(
        User $actor,
        AddressBook $sourceAddressBook,
        ?AddressBookPrivateWorkingSetConfig $config,
    ): array {
        if ((int) $sourceAddressBook->owner_id !== (int) $actor->id) {
            return [];
        }

        if (! $this->settingsService->isContactChangeModerationEnabled()) {
            return [];
        }

        if (! $this->effectiveRequireReviewForSelfPromotions(
            $actor,
            $this->resolveRequireReviewForSelfPromotions($actor, $config),
        )) {
            return [];
        }

        if ($actor->isAdmin()) {
            return [$actor->id];
        }

        $adminReviewerId = User::query()
            ->where('role', Role::Admin->value)
            ->orderBy('id')
            ->value('id');

        if ($adminReviewerId === null) {
            abort(422, __('contacts.private_working_set_no_admin_reviewer_available'));
        }

        return [(int) $adminReviewerId];
    }

    /**
     * Resolves private address book.
     */
    private function resolvePrivateAddressBook(
        User $user,
        ?AddressBookPrivateWorkingSetConfig $config = null,
    ): ?AddressBook {
        $configuredId = (int) ($config?->private_address_book_id ?? 0);
        if ($configuredId > 0) {
            $configured = AddressBook::query()
                ->where('id', $configuredId)
                ->where('owner_id', $user->id)
                ->first();
            if ($configured) {
                return $configured;
            }
        }

        return AddressBook::query()
            ->where('owner_id', $user->id)
            ->where('uri', 'private-working-set')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ensures private address book exists.
     */
    private function ensurePrivateAddressBook(
        User $user,
        ?AddressBookPrivateWorkingSetConfig $config = null,
    ): AddressBook {
        $existing = $this->resolvePrivateAddressBook($user, $config);
        if ($existing) {
            if ($config && (int) $config->private_address_book_id !== (int) $existing->id) {
                $config->update([
                    'private_address_book_id' => $existing->id,
                ]);
            }

            return $existing;
        }

        $base = 'private-working-set';
        $uri = $base;
        $suffix = 1;
        while (
            AddressBook::query()
                ->where('owner_id', $user->id)
                ->where('uri', $uri)
                ->exists()
        ) {
            $uri = $base.'-'.$suffix;
            $suffix++;
        }

        $addressBook = AddressBook::query()->create([
            'owner_id' => $user->id,
            'uri' => $uri,
            'display_name' => 'Private Working Set',
            'description' => 'Private per-user working set for shared contacts.',
            'is_default' => false,
            'is_sharable' => false,
        ]);

        $this->syncService->ensureResource(ShareResourceType::AddressBook, $addressBook->id);

        if ($config) {
            $config->update([
                'private_address_book_id' => $addressBook->id,
            ]);
        }

        return $addressBook;
    }

    /**
     * Checks whether user can use source address book.
     */
    private function userCanUseSourceAddressBook(
        User $user,
        int $sourceAddressBookId,
        ?bool $includeOwnedSharableSources = null,
    ): bool {
        $canUseSharedSource = ResourceShare::query()
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('resource_id', $sourceAddressBookId)
            ->where('shared_with_id', $user->id)
            ->exists();

        if ($canUseSharedSource) {
            return true;
        }

        $includeOwned = $includeOwnedSharableSources;
        if ($includeOwned === null) {
            $config = AddressBookPrivateWorkingSetConfig::query()
                ->where('user_id', $user->id)
                ->first();
            $includeOwned = $this->resolveIncludeOwnedSharableSources($user, $config);
        }

        if (! $includeOwned) {
            return false;
        }

        return AddressBook::query()
            ->where('id', $sourceAddressBookId)
            ->where('owner_id', $user->id)
            ->where('is_sharable', true)
            ->exists();
    }

    /**
     * Synchronizes source address book for user.
     */
    private function syncSourceAddressBookForUser(
        User $user,
        AddressBook $privateAddressBook,
        int $sourceAddressBookId,
        bool $includeOwnedSharableSources,
        bool $forceServer = false,
    ): void {
        if (
            ! $this->userCanUseSourceAddressBook(
                user: $user,
                sourceAddressBookId: $sourceAddressBookId,
                includeOwnedSharableSources: $includeOwnedSharableSources,
            )
        ) {
            $this->removePrivateLinksForUser(
                userId: $user->id,
                sourceAddressBookIds: [$sourceAddressBookId],
            );

            return;
        }

        $sourceAddressBook = AddressBook::query()->find($sourceAddressBookId);
        if (! $sourceAddressBook) {
            $this->removePrivateLinksForUser(
                userId: $user->id,
                sourceAddressBookIds: [$sourceAddressBookId],
            );

            return;
        }

        $sourceCards = Card::query()
            ->where('address_book_id', $sourceAddressBookId)
            ->orderBy('id')
            ->get();

        $seenUris = [];
        foreach ($sourceCards as $sourceCard) {
            if ($this->isPrivateManagedCard($sourceCard->data)) {
                continue;
            }

            $this->upsertPrivateCard(
                user: $user,
                privateAddressBook: $privateAddressBook,
                sourceAddressBook: $sourceAddressBook,
                sourceCard: $sourceCard,
                forceServer: $forceServer,
            );
            $seenUris[$sourceCard->uri] = true;
        }

        $links = AddressBookPrivateWorkingSetLink::query()
            ->where('user_id', $user->id)
            ->where('source_address_book_id', $sourceAddressBookId)
            ->get();

        foreach ($links as $link) {
            if (! isset($seenUris[$link->source_card_uri])) {
                $this->deletePrivateLink($link);
            }
        }
    }

    /**
     * Performs upsert private card.
     */
    private function upsertPrivateCard(
        User $user,
        AddressBook $privateAddressBook,
        AddressBook $sourceAddressBook,
        Card $sourceCard,
        bool $forceServer = false,
    ): void {
        $sourceParsed = $this->vCardService->parse($sourceCard->data);
        $sourcePayload = is_array($sourceParsed) && is_array($sourceParsed['payload'] ?? null)
            ? $sourceParsed['payload']
            : null;

        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('user_id', $user->id)
            ->where('source_address_book_id', $sourceAddressBook->id)
            ->where('source_card_uri', $sourceCard->uri)
            ->first();

        $privateCard = $link
            ? Card::query()->find($link->private_card_id)
            : null;

        $privateUid = $privateCard?->uid ?? $this->privateUid($user->id, $sourceAddressBook->id, $sourceCard->uri);

        if (! $privateCard) {
            $privateUri = $this->privateUri($user->id, $sourceAddressBook->id, $sourceCard->uri);
            $privateData = $this->buildPrivateCardDataFromSource(
                sourceCardData: $sourceCard->data,
                privateUid: $privateUid,
                userId: $user->id,
                sourceAddressBookId: $sourceAddressBook->id,
                sourceCardUri: $sourceCard->uri,
            );

            if ($privateData === null) {
                return;
            }

            $privateCard = Card::query()->create([
                'address_book_id' => $privateAddressBook->id,
                'uri' => $privateUri,
                'uid' => $privateUid,
                'etag' => md5($privateData),
                'size' => strlen($privateData),
                'data' => $privateData,
            ]);

            $this->syncService->recordAdded(
                resourceType: ShareResourceType::AddressBook,
                resourceId: $privateAddressBook->id,
                uri: $privateCard->uri,
            );
        } else {
            $privateData = null;
            $overrides = $forceServer
                ? []
                : $this->sanitizeOverrideFields($link?->overridden_fields ?? []);

            if ($sourcePayload !== null && $overrides !== []) {
                $privateParsed = $this->vCardService->parse($privateCard->data);
                if (is_array($privateParsed) && is_array($privateParsed['payload'] ?? null)) {
                    $mergedPayload = $this->mergePayload(
                        sourcePayload: $sourcePayload,
                        privatePayload: $privateParsed['payload'],
                        overriddenFields: $overrides,
                    );

                    $privateData = $this->buildPrivateCardDataFromPayload(
                        user: $user,
                        privateUid: $privateUid,
                        payload: $mergedPayload,
                        sourceCardData: $sourceCard->data,
                        privateCardData: $privateCard->data,
                        keepPrivatePhoto: in_array('photo', $overrides, true),
                        sourceAddressBookId: $sourceAddressBook->id,
                        sourceCardUri: $sourceCard->uri,
                    );
                }
            }

            if ($privateData === null) {
                $privateData = $this->buildPrivateCardDataFromSource(
                    sourceCardData: $sourceCard->data,
                    privateUid: $privateUid,
                    userId: $user->id,
                    sourceAddressBookId: $sourceAddressBook->id,
                    sourceCardUri: $sourceCard->uri,
                );
            }

            if ($privateData === null) {
                return;
            }

            $privateEtag = md5($privateData);
            $privateSize = strlen($privateData);

            $isNoOp = $privateCard->uid === $privateUid
                && $privateCard->etag === $privateEtag
                && (int) $privateCard->size === $privateSize
                && $privateCard->data === $privateData;

            if (! $isNoOp) {
                $privateCard->update([
                    'uid' => $privateUid,
                    'etag' => $privateEtag,
                    'size' => $privateSize,
                    'data' => $privateData,
                ]);

                $this->syncService->recordModified(
                    resourceType: ShareResourceType::AddressBook,
                    resourceId: $privateAddressBook->id,
                    uri: $privateCard->uri,
                );

                $privateCard->fill([
                    'uid' => $privateUid,
                    'etag' => $privateEtag,
                    'size' => $privateSize,
                    'data' => $privateData,
                ]);
            }
        }

        if (! $link) {
            AddressBookPrivateWorkingSetLink::query()->create([
                'user_id' => $user->id,
                'source_address_book_id' => $sourceAddressBook->id,
                'source_card_uri' => $sourceCard->uri,
                'source_card_uid' => $sourceCard->uid,
                'source_payload' => $sourcePayload,
                'overridden_fields' => [],
                'private_address_book_id' => $privateAddressBook->id,
                'private_card_id' => $privateCard->id,
            ]);

            return;
        }

        $attributes = [
            'source_card_uid' => $sourceCard->uid,
            'source_payload' => $sourcePayload,
            'private_address_book_id' => $privateAddressBook->id,
            'private_card_id' => $privateCard->id,
        ];

        if ($forceServer) {
            $attributes['overridden_fields'] = [];
        }

        $link->update($attributes);
    }

    /**
     * Builds private card payload from source card.
     */
    private function buildPrivateCardDataFromSource(
        string $sourceCardData,
        string $privateUid,
        int $userId,
        int $sourceAddressBookId,
        string $sourceCardUri,
    ): ?string {
        try {
            $vcard = Reader::read($sourceCardData);
        } catch (Throwable) {
            return null;
        }

        if (! $vcard instanceof VCard) {
            return null;
        }

        $this->setSingleUid($vcard, $privateUid);
        $this->removePrivateMetadata($vcard);
        $this->addPrivateMetadata($vcard, $userId, $sourceAddressBookId, $sourceCardUri);

        $data = $vcard->serialize();
        $vcard->destroy();

        return $data;
    }

    /**
     * Builds private card payload from merged payload fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildPrivateCardDataFromPayload(
        User $user,
        string $privateUid,
        array $payload,
        string $sourceCardData,
        string $privateCardData,
        bool $keepPrivatePhoto,
        int $sourceAddressBookId,
        string $sourceCardUri,
    ): ?string {
        $contact = new Contact([
            'owner_id' => $user->id,
            'uid' => $privateUid,
            'payload' => $payload,
        ]);

        $raw = $this->vCardService->build($contact);

        try {
            $vcard = Reader::read($raw);
        } catch (Throwable) {
            return null;
        }

        if (! $vcard instanceof VCard) {
            return null;
        }

        $this->setSingleUid($vcard, $privateUid);
        $this->removePrivateMetadata($vcard);
        $this->addPrivateMetadata($vcard, $user->id, $sourceAddressBookId, $sourceCardUri);
        $this->replacePhotoFromCardData(
            target: $vcard,
            sourceCardData: $keepPrivatePhoto ? $privateCardData : $sourceCardData,
        );

        $data = $vcard->serialize();
        $vcard->destroy();

        return $data;
    }

    /**
     * Replaces PHOTO property in target card from source card payload.
     */
    private function replacePhotoFromCardData(VCard $target, string $sourceCardData): void
    {
        foreach ($target->select('PHOTO') as $photoProperty) {
            $photoProperty->destroy();
        }

        try {
            $source = Reader::read($sourceCardData);
        } catch (Throwable) {
            return;
        }

        if (! $source instanceof VCard) {
            return;
        }

        $sourcePhoto = $source->select('PHOTO')[0] ?? null;
        if ($sourcePhoto === null) {
            return;
        }

        try {
            $target->add(clone $sourcePhoto);
        } catch (Throwable) {
            $fallback = $target->add('PHOTO', (string) $sourcePhoto);
            foreach (['ENCODING', 'TYPE', 'MEDIATYPE'] as $parameter) {
                if (isset($sourcePhoto[$parameter])) {
                    $fallback[$parameter] = (string) $sourcePhoto[$parameter];
                }
            }
        }
    }

    /**
     * Sets the first UID property and removes extras.
     */
    private function setSingleUid(VCard $vcard, string $uid): void
    {
        $uidProperties = $vcard->select('UID');
        if ($uidProperties !== []) {
            $uidProperties[0]->setValue($uid);

            foreach (array_slice($uidProperties, 1) as $property) {
                $property->destroy();
            }

            return;
        }

        $vcard->add('UID', $uid);
    }

    /**
     * Removes private metadata properties.
     */
    private function removePrivateMetadata(VCard $vcard): void
    {
        foreach ($vcard->select(self::PRIVATE_SOURCE_PROPERTY) as $property) {
            $property->destroy();
        }

        foreach ($vcard->select(self::PRIVATE_OWNER_PROPERTY) as $property) {
            $property->destroy();
        }
    }

    /**
     * Adds private metadata properties.
     */
    private function addPrivateMetadata(
        VCard $vcard,
        int $userId,
        int $sourceAddressBookId,
        string $sourceCardUri,
    ): void {
        $vcard->add(self::PRIVATE_SOURCE_PROPERTY, $sourceAddressBookId.'/'.$sourceCardUri);
        $vcard->add(self::PRIVATE_OWNER_PROPERTY, (string) $userId);
    }

    /**
     * Converts private update into source payload.
     */
    private function sourcePayloadFromPrivateUpdate(string $incomingCardData, string $sourceUid): string
    {
        try {
            $vcard = Reader::read($incomingCardData);
        } catch (Throwable) {
            throw new BadRequest(__('dav.invalid_vcard_payload'));
        }

        if (! $vcard instanceof VCard) {
            throw new BadRequest(__('dav.expected_vcard_payload'));
        }

        $this->setSingleUid($vcard, $sourceUid);
        $this->removePrivateMetadata($vcard);

        $data = $vcard->serialize();
        $vcard->destroy();

        return $data;
    }

    /**
     * Returns private URI.
     */
    private function privateUri(int $userId, int $sourceAddressBookId, string $sourceCardUri): string
    {
        $hash = substr(sha1($userId.'|'.$sourceAddressBookId.'|'.$sourceCardUri), 0, 24);

        return sprintf('private-u%d-b%d-%s.vcf', $userId, $sourceAddressBookId, $hash);
    }

    /**
     * Returns private UID.
     */
    private function privateUid(int $userId, int $sourceAddressBookId, string $sourceCardUri): string
    {
        $hash = substr(sha1($userId.'|'.$sourceAddressBookId.'|'.$sourceCardUri), 0, 24);

        return sprintf('davvy-private-%d-%d-%s', $userId, $sourceAddressBookId, $hash);
    }

    /**
     * Returns overridden payload fields.
     *
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $privatePayload
     * @return array<int, string>
     */
    private function overriddenFieldsForPayload(
        array $sourcePayload,
        array $privatePayload,
        ?string $sourceCardData,
        string $privateCardData,
    ): array {
        $overridden = [];

        foreach (self::OVERRIDABLE_PAYLOAD_FIELDS as $field) {
            if ($field === 'photo') {
                continue;
            }

            $sourceValue = $sourcePayload[$field] ?? null;
            $privateValue = $privatePayload[$field] ?? null;

            if ($this->comparisonKey($sourceValue) !== $this->comparisonKey($privateValue)) {
                $overridden[] = $field;
            }
        }

        if (
            $sourceCardData !== null
            && $this->photoFingerprint($sourceCardData) !== $this->photoFingerprint($privateCardData)
        ) {
            $overridden[] = 'photo';
        }

        return $this->sanitizeOverrideFields($overridden);
    }

    /**
     * Merges source/private payload where private overridden fields win.
     *
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $privatePayload
     * @param  array<int, string>  $overriddenFields
     * @return array<string, mixed>
     */
    private function mergePayload(array $sourcePayload, array $privatePayload, array $overriddenFields): array
    {
        $merged = $sourcePayload;

        foreach ($overriddenFields as $field) {
            if ($field === 'photo') {
                continue;
            }

            if (array_key_exists($field, $privatePayload)) {
                $merged[$field] = $privatePayload[$field];
            }
        }

        return $merged;
    }

    /**
     * Returns comparable key for value.
     */
    private function comparisonKey(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /**
     * Returns photo fingerprint for first PHOTO property.
     */
    private function photoFingerprint(string $cardData): ?string
    {
        try {
            $vcard = Reader::read($cardData);
        } catch (Throwable) {
            return null;
        }

        if (! $vcard instanceof VCard) {
            return null;
        }

        $photo = $vcard->select('PHOTO')[0] ?? null;
        if ($photo === null) {
            return null;
        }

        return hash('sha256', $photo->serialize());
    }

    /**
     * Sanitizes override fields.
     *
     * @return array<int, string>
     */
    private function sanitizeOverrideFields(mixed $value): array
    {
        $allowed = array_fill_keys(self::OVERRIDABLE_PAYLOAD_FIELDS, true);
        $rows = is_array($value) ? $value : [];

        $normalized = collect($rows)
            ->map(fn (mixed $row): string => Str::lower(trim((string) $row)))
            ->filter(fn (string $row): bool => $row !== '' && isset($allowed[$row]))
            ->unique()
            ->values()
            ->all();

        sort($normalized);

        return $normalized;
    }

    /**
     * Returns suggested promotion rows for dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dashboardSuggestedPromotionsFor(User $user): array
    {
        $links = AddressBookPrivateWorkingSetLink::query()
            ->with('privateCard')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $sourceAddressBooks = AddressBook::query()
            ->whereIn('id', $links->pluck('source_address_book_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($links as $link) {
            $sourceAddressBook = $sourceAddressBooks->get((int) $link->source_address_book_id);
            if (! $sourceAddressBook || ! $this->accessService->userCanWriteAddressBook($user, $sourceAddressBook)) {
                continue;
            }

            $state = $this->suggestionStateForLink($link);
            if (! is_array($state)) {
                continue;
            }

            $fingerprint = (string) ($state['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }

            $dismissedFingerprint = trim((string) ($link->dismissed_suggestion_fingerprint ?? ''));
            if ($dismissedFingerprint !== '' && hash_equals($dismissedFingerprint, $fingerprint)) {
                continue;
            }

            $rows[] = $state;
        }

        return $rows;
    }

    /**
     * Returns current suggestion state for one link.
     *
     * @return array<string, mixed>|null
     */
    private function suggestionStateForLink(AddressBookPrivateWorkingSetLink $link): ?array
    {
        $privateCard = $link->privateCard ?? Card::query()->find($link->private_card_id);
        if (! $privateCard) {
            return null;
        }

        $privateParsed = $this->vCardService->parse($privateCard->data);
        $privatePayload = is_array($privateParsed) && is_array($privateParsed['payload'] ?? null)
            ? $privateParsed['payload']
            : null;
        if (! is_array($privatePayload)) {
            return null;
        }

        $sourcePayload = is_array($link->source_payload) ? $link->source_payload : null;
        if (! is_array($sourcePayload)) {
            $sourceCard = Card::query()
                ->where('address_book_id', $link->source_address_book_id)
                ->where('uri', $link->source_card_uri)
                ->first();
            if (! $sourceCard) {
                return null;
            }

            $sourceParsed = $this->vCardService->parse($sourceCard->data);
            $sourcePayload = is_array($sourceParsed) && is_array($sourceParsed['payload'] ?? null)
                ? $sourceParsed['payload']
                : null;
        }

        if (! is_array($sourcePayload)) {
            return null;
        }

        $suggestedFields = $this->suggestedPromotionFields($link->overridden_fields ?? []);
        if ($suggestedFields === []) {
            return null;
        }

        $fingerprint = $this->suggestionFingerprint(
            suggestedFields: $suggestedFields,
            sourcePayload: $sourcePayload,
            privatePayload: $privatePayload,
            sourceAddressBookId: (int) $link->source_address_book_id,
            sourceCardUri: (string) $link->source_card_uri,
        );

        $displayName = $this->vCardService->displayName($privatePayload);

        return [
            'link_id' => $link->id,
            'private_card_id' => $link->private_card_id,
            'private_card_uri' => $privateCard->uri,
            'source_address_book_id' => $link->source_address_book_id,
            'source_card_uri' => $link->source_card_uri,
            'display_name' => $displayName,
            'suggested_fields' => $suggestedFields,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Returns conservative suggestable promotion fields from overrides.
     *
     * @return array<int, string>
     */
    private function suggestedPromotionFields(mixed $value): array
    {
        $overridden = $this->sanitizeOverrideFields($value);
        if ($overridden === []) {
            return [];
        }

        $allowlist = array_fill_keys(self::SUGGESTABLE_PROMOTION_FIELDS, true);

        return collect($overridden)
            ->filter(fn (string $field): bool => isset($allowlist[$field]))
            ->values()
            ->all();
    }

    /**
     * Builds deterministic suggestion fingerprint for current state.
     *
     * @param  array<int, string>  $suggestedFields
     * @param  array<string, mixed>  $sourcePayload
     * @param  array<string, mixed>  $privatePayload
     */
    private function suggestionFingerprint(
        array $suggestedFields,
        array $sourcePayload,
        array $privatePayload,
        int $sourceAddressBookId,
        string $sourceCardUri,
    ): string {
        $normalized = [
            'source_address_book_id' => $sourceAddressBookId,
            'source_card_uri' => $sourceCardUri,
            'fields' => [],
        ];

        foreach ($suggestedFields as $field) {
            $normalized['fields'][$field] = [
                'source' => $sourcePayload[$field] ?? null,
                'private' => $privatePayload[$field] ?? null,
            ];
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded !== false ? $encoded : serialize($normalized));
    }

    /**
     * Marks current link suggestion as dismissed.
     */
    private function dismissCurrentSuggestion(AddressBookPrivateWorkingSetLink $link): void
    {
        $state = $this->suggestionStateForLink($link);
        $fingerprint = is_array($state) ? (string) ($state['fingerprint'] ?? '') : '';
        if ($fingerprint === '') {
            return;
        }

        $link->update([
            'dismissed_suggestion_fingerprint' => $fingerprint,
            'dismissed_suggestion_at' => now(),
        ]);
    }

    /**
     * Returns linked card rows for dashboard promote actions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dashboardLinkedCardsFor(User $user): array
    {
        return AddressBookPrivateWorkingSetLink::query()
            ->with('privateCard')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (AddressBookPrivateWorkingSetLink $link): array {
                $privateCard = $link->privateCard;
                $parsed = $privateCard
                    ? $this->vCardService->parse($privateCard->data)
                    : null;
                $payload = is_array($parsed) && is_array($parsed['payload'] ?? null)
                    ? $parsed['payload']
                    : [];
                $displayName = $this->vCardService->displayName($payload);

                return [
                    'link_id' => $link->id,
                    'private_card_id' => $link->private_card_id,
                    'private_card_uri' => $privateCard?->uri,
                    'source_address_book_id' => $link->source_address_book_id,
                    'source_card_uri' => $link->source_card_uri,
                    'display_name' => $displayName,
                    'overridden_fields' => $this->sanitizeOverrideFields($link->overridden_fields ?? []),
                ];
            })
            ->all();
    }

    /**
     * Removes private links for user.
     */
    private function removePrivateLinksForUser(
        int $userId,
        ?array $sourceAddressBookIds = null,
        ?array $exceptSourceAddressBookIds = null,
    ): void {
        $query = AddressBookPrivateWorkingSetLink::query()->where('user_id', $userId);

        if ($sourceAddressBookIds !== null) {
            $query->whereIn('source_address_book_id', $sourceAddressBookIds);
        }

        if ($exceptSourceAddressBookIds !== null) {
            $query->whereNotIn('source_address_book_id', $exceptSourceAddressBookIds);
        }

        $links = $query->get();
        foreach ($links as $link) {
            $this->deletePrivateLink($link);
        }
    }

    /**
     * Deletes private link and card.
     */
    private function deletePrivateLink(AddressBookPrivateWorkingSetLink $link): void
    {
        $privateCard = Card::query()->find($link->private_card_id);
        if ($privateCard) {
            $uri = $privateCard->uri;
            $resourceId = $privateCard->address_book_id;

            $privateCard->delete();

            $this->syncService->recordDeleted(
                resourceType: ShareResourceType::AddressBook,
                resourceId: $resourceId,
                uri: $uri,
            );
        }

        $link->delete();
    }

    /**
     * Checks whether uid conflict exists.
     */
    private function uidConflictExists(int $addressBookId, string $uid, ?int $exceptCardId = null): bool
    {
        $query = Card::query()
            ->where('address_book_id', $addressBookId)
            ->where('uid', $uid);

        if ($exceptCardId !== null) {
            $query->where('id', '!=', $exceptCardId);
        }

        return $query->exists();
    }

    /**
     * Returns contact change request service.
     */
    private function contactChangeRequestService(): ContactChangeRequestService
    {
        return app(ContactChangeRequestService::class);
    }
}
