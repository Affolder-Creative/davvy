<?php

namespace App\Services;

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
use Sabre\DAV\Exception\Forbidden;
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

    public function __construct(
        private readonly DavSyncService $syncService,
        private readonly ResourceAccessService $accessService,
        private readonly VCardValidator $vCardValidator,
        private readonly ContactVCardService $vCardService,
        private readonly ManagedContactSyncService $managedContactSync,
        private readonly AddressBookMirrorService $mirrorService,
    ) {}

    /**
     * Returns dashboard data.
     */
    public function dashboardDataFor(User $user): array
    {
        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        $privateAddressBook = $this->resolvePrivateAddressBook($user, $config);
        $sourceOptions = $this->eligibleSharedSourceOptionsForUser($user);
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
            'private_address_book_id' => $privateAddressBook?->id,
            'private_address_book_uri' => $privateAddressBook?->uri,
            'private_display_name' => $privateAddressBook?->display_name,
            'selected_source_ids' => $selected,
            'source_options' => $sourceOptions->all(),
            'linked_cards' => $this->dashboardLinkedCardsFor($user),
        ];
    }

    /**
     * Updates user config.
     */
    public function updateUserConfig(User $user, bool $enabled, bool $hideShared, array $sourceIds): array
    {
        $sourceOptions = $this->eligibleSharedSourceOptionsForUser($user);
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
        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        if (! $config) {
            return;
        }

        $privateAddressBook = $this->resolvePrivateAddressBook($user, $config);
        $selectedIds = collect($config->sources)
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->userCanUseSourceAddressBook($user, $id))
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
                forceServer: $forceServer,
            );
        }
    }

    /**
     * Pulls latest source changes into private working set.
     */
    public function pullLatest(User $user, bool $forceServer = false): array
    {
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
        $link = AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $link) {
            throw new Forbidden(__('contacts.cannot_modify_private_working_set_card'));
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
            throw new Forbidden(__('contacts.write_access_denied_for_private_working_set_source_address_book'));
        }

        $sourceUid = trim((string) $sourceCard->uid);
        if ($sourceUid === '') {
            $sourceUid = 'legacy-card-'.sha1($link->source_card_uri);
        }

        $normalized = $this->vCardValidator->validateAndNormalize(
            $this->sourcePayloadFromPrivateUpdate($privateCard->data, $sourceUid),
        );

        $queued = $this->contactChangeRequestService()->enqueueCardDavUpdateIfNeeded(
            actor: $actor,
            addressBook: $sourceAddressBook,
            card: $sourceCard,
            normalizedCardData: $normalized['data'],
        );

        if ($queued !== null) {
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

        return [
            'queued' => false,
            'applied' => true,
            'source_address_book_id' => $sourceAddressBook->id,
            'source_card_uri' => $sourceCard->uri,
            'source_card_etag' => $sourceCard->etag,
        ];
    }

    /**
     * Returns source address book IDs hidden from CardDAV discovery for the user.
     *
     * @return array<int, int>
     */
    public function hiddenSourceAddressBookIdsForUser(User $user): array
    {
        $config = AddressBookPrivateWorkingSetConfig::query()
            ->with('sources')
            ->where('user_id', $user->id)
            ->first();

        if (! $config || ! $config->enabled || ! $config->hide_shared) {
            return [];
        }

        return collect($config->sources)
            ->pluck('source_address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $this->userCanUseSourceAddressBook($user, $id))
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
        AddressBookPrivateWorkingSetLink::query()
            ->where('private_card_id', $privateCard->id)
            ->delete();
    }

    /**
     * Returns eligible source options for user.
     *
     * @return Collection<int, array{id:int,uri:string,display_name:string,scope:string,owner_name:?string,owner_email:?string,permission:string,can_write:bool}>
     */
    private function eligibleSharedSourceOptionsForUser(User $user): Collection
    {
        return ResourceShare::query()
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
            })
            ->unique('id')
            ->sortBy(fn (array $item): string => mb_strtolower($item['display_name']))
            ->values();
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
    private function userCanUseSourceAddressBook(User $user, int $sourceAddressBookId): bool
    {
        return ResourceShare::query()
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('resource_id', $sourceAddressBookId)
            ->where('shared_with_id', $user->id)
            ->exists();
    }

    /**
     * Synchronizes source address book for user.
     */
    private function syncSourceAddressBookForUser(
        User $user,
        AddressBook $privateAddressBook,
        int $sourceAddressBookId,
        bool $forceServer = false,
    ): void {
        if (! $this->userCanUseSourceAddressBook($user, $sourceAddressBookId)) {
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
