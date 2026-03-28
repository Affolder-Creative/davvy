<?php

namespace App\Services\Dav\Backends;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Card;
use App\Models\ResourceShare;
use App\Services\AddressBookMirrorService;
use App\Services\Contacts\ContactChangeRequestService;
use App\Services\Contacts\ContactMilestoneCalendarService;
use App\Services\Contacts\ManagedContactSyncService;
use App\Services\Dav\DavSyncService;
use App\Services\Dav\VCardValidator;
use App\Services\DavRequestContext;
use App\Services\PrincipalUriService;
use App\Services\ResourceAccessService;
use App\Services\ResourceDeletionService;
use App\Services\ResourceUriService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\InvalidSyncToken;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Throwable;

class LaravelCardDavBackend extends AbstractBackend implements SyncSupport
{
    public function __construct(
        private readonly PrincipalUriService $principalUriService,
        private readonly ResourceUriService $resourceUriService,
        private readonly ResourceAccessService $accessService,
        private readonly DavRequestContext $davContext,
        private readonly VCardValidator $vCardValidator,
        private readonly DavSyncService $syncService,
        private readonly AddressBookMirrorService $mirrorService,
        private readonly ManagedContactSyncService $managedContactSync,
        private readonly ContactMilestoneCalendarService $milestoneCalendarService,
        private readonly ContactChangeRequestService $changeRequestService,
        private readonly ResourceDeletionService $resourceDeletion,
    ) {}

    /**
     * Returns address books accessible to the principal.
     *
     * @param  mixed  $principalUri
     */
    public function getAddressBooksForUser($principalUri): array
    {
        $owner = $this->principalUriService->userFromPrincipalUri($principalUri);

        if (! $owner) {
            return [];
        }

        $own = AddressBook::query()
            ->where('owner_id', $owner->id)
            ->get()
            ->map(fn (AddressBook $addressBook): array => $this->transformAddressBook($addressBook, SharePermission::Admin, $principalUri))
            ->all();

        $shared = ResourceShare::query()
            ->with('addressBook')
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('shared_with_id', $owner->id)
            ->get()
            ->filter(fn (ResourceShare $share): bool => $share->addressBook !== null)
            ->map(function (ResourceShare $share) use ($principalUri): array {
                return $this->transformAddressBook($share->addressBook, $share->permission, $principalUri);
            })
            ->all();

        return [...$own, ...$shared];
    }

    /**
     * Updates properties for a writable address book.
     *
     * @param  mixed  $addressBookId
     */
    public function updateAddressBook($addressBookId, PropPatch $propPatch): void
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            throw new NotFound(__('dav.address_book_not_found'));
        }

        $this->assertWritableAddressBook($addressBook);

        $propPatch->handle([
            '{DAV:}displayname',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description',
        ], function (array $mutations) use ($addressBook): bool {
            if (array_key_exists('{DAV:}displayname', $mutations)) {
                $addressBook->display_name = (string) $mutations['{DAV:}displayname'];
            }

            if (array_key_exists('{urn:ietf:params:xml:ns:carddav}addressbook-description', $mutations)) {
                $addressBook->description = $mutations['{urn:ietf:params:xml:ns:carddav}addressbook-description'];
            }

            $addressBook->save();
            $this->milestoneCalendarService->handleAddressBookRenamed($addressBook->fresh());

            return true;
        });
    }

    /**
     * Creates an address book for the principal owner.
     *
     * @param  mixed  $principalUri
     * @param  mixed  $url
     */
    public function createAddressBook($principalUri, $url, array $properties): void
    {
        $user = $this->principalUriService->userFromPrincipalUri($principalUri);

        if (! $user) {
            throw new NotFound(__('dav.principal_does_not_exist'));
        }

        $uri = $this->resourceUriService->nextAddressBookUri(
            ownerId: (int) $user->id,
            candidate: (string) $url,
        );

        try {
            $addressBook = AddressBook::query()->create([
                'owner_id' => $user->id,
                'uri' => $uri,
                'display_name' => (string) ($properties['{DAV:}displayname'] ?? 'Address Book'),
                'description' => $properties['{urn:ietf:params:xml:ns:carddav}addressbook-description'] ?? null,
                'is_default' => false,
                'is_sharable' => false,
            ]);
        } catch (QueryException $exception) {
            if ($this->isOwnerUriUniqueConstraintViolation($exception)) {
                throw new Conflict(__('dav.address_book_already_exists_for_requested_uri'));
            }

            throw $exception;
        }

        $this->syncService->ensureResource(ShareResourceType::AddressBook, $addressBook->id);
    }

    /**
     * Deletes a writable address book.
     *
     * @param  mixed  $addressBookId
     */
    public function deleteAddressBook($addressBookId): void
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            return;
        }

        $this->assertDeletableAddressBook($addressBook);

        $this->resourceDeletion->deleteAddressBook($addressBook);
    }

    /**
     * Returns cards for an address book.
     *
     * @param  mixed  $addressBookId
     */
    public function getCards($addressBookId): array
    {
        $addressBook = $this->loadReadableAddressBook($addressBookId);

        return Card::query()
            ->where('address_book_id', $addressBook->id)
            ->select(['id', 'uri', 'etag', 'size', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->map(fn (Card $card): array => $this->transformCard($card, withData: false))
            ->all();
    }

    /**
     * Returns a single card by URI.
     *
     * @param  mixed  $addressBookId
     * @param  mixed  $cardUri
     */
    public function getCard($addressBookId, $cardUri): ?array
    {
        $addressBook = $this->loadReadableAddressBook($addressBookId);

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', $cardUri)
            ->first();

        if (! $card) {
            return null;
        }

        return $this->transformCard($card, withData: true);
    }

    /**
     * Returns multiple cards by URI.
     *
     * @param  mixed  $addressBookId
     */
    public function getMultipleCards($addressBookId, array $uris): array
    {
        $addressBook = $this->loadReadableAddressBook($addressBookId);

        return Card::query()
            ->where('address_book_id', $addressBook->id)
            ->whereIn('uri', $uris)
            ->get()
            ->map(fn (Card $card): array => $this->transformCard($card, withData: true))
            ->all();
    }

    /**
     * Creates a card and records sync changes.
     *
     * @param  mixed  $addressBookId
     * @param  mixed  $cardUri
     * @param  mixed  $cardData
     */
    public function createCard($addressBookId, $cardUri, $cardData): string
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            throw new NotFound(__('dav.address_book_not_found'));
        }

        $this->assertWritableAddressBook($addressBook);

        $existing = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', $cardUri)
            ->exists();

        if ($existing) {
            throw new BadRequest(__('dav.card_already_exists_for_requested_uri'));
        }

        $normalized = $this->vCardValidator->validateAndNormalize((string) $cardData);
        $resourceUid = $normalized['uid'] ?? $this->fallbackUidForLegacyPayload((string) $cardUri);

        if ($this->uidConflictExists($addressBook->id, $resourceUid)) {
            throw new Conflict(__('dav.contact_with_same_uid_exists_in_address_book'));
        }

        $etag = md5($normalized['data']);

        try {
            $card = Card::query()->create([
                'address_book_id' => $addressBook->id,
                'uri' => $cardUri,
                'uid' => $resourceUid,
                'etag' => $etag,
                'size' => strlen($normalized['data']),
                'data' => $normalized['data'],
            ]);
        } catch (QueryException $exception) {
            if ($this->isUidUniqueConstraintViolation($exception)) {
                throw new Conflict(__('dav.contact_with_same_uid_exists_in_address_book'));
            }

            throw $exception;
        }

        $this->syncService->recordAdded(ShareResourceType::AddressBook, $addressBook->id, (string) $cardUri);
        $this->mirrorService->handleSourceCardUpsert($addressBook, $card);
        $this->syncManagedContactUpsert($addressBook, $card);

        return '"'.$etag.'"';
    }

    /**
     * Updates a card and records sync changes.
     *
     * @param  mixed  $addressBookId
     * @param  mixed  $cardUri
     * @param  mixed  $cardData
     */
    public function updateCard($addressBookId, $cardUri, $cardData): string
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            throw new NotFound(__('dav.address_book_not_found'));
        }

        $this->assertWritableAddressBook($addressBook);

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', $cardUri)
            ->first();

        if (! $card) {
            throw new NotFound(__('dav.card_not_found'));
        }

        $user = $this->davContext->getAuthenticatedUser();
        $mirroredEtag = $this->mirrorService->updateSourceFromMirroredCard(
            actor: $user,
            mirroredCard: $card,
            incomingCardData: (string) $cardData,
        );
        if ($mirroredEtag !== null) {
            return '"'.$mirroredEtag.'"';
        }

        $normalized = $this->vCardValidator->validateAndNormalize((string) $cardData);

        if ($user) {
            $queued = $this->changeRequestService->enqueueCardDavUpdateIfNeeded(
                actor: $user,
                addressBook: $addressBook,
                card: $card,
                normalizedCardData: $normalized['data'],
            );

            if ($queued !== null) {
                throw new Conflict(__('dav.change_submitted_for_owner_or_admin_approval'));
            }
        }

        $resourceUid = $normalized['uid'] ?? $this->fallbackUidForLegacyPayload((string) $cardUri);

        if ($this->uidConflictExists($addressBook->id, $resourceUid, exceptCardId: $card->id)) {
            throw new Conflict(__('dav.contact_with_same_uid_exists_in_address_book'));
        }

        $normalizedData = $normalized['data'];
        $size = strlen($normalizedData);
        $etag = md5($normalizedData);
        $isNoOp = $card->uid === $resourceUid
            && $card->etag === $etag
            && (int) $card->size === $size
            && $card->data === $normalizedData;

        if ($isNoOp) {
            return '"'.$etag.'"';
        }

        try {
            $card->update([
                'uid' => $resourceUid,
                'etag' => $etag,
                'size' => $size,
                'data' => $normalizedData,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUidUniqueConstraintViolation($exception)) {
                throw new Conflict(__('dav.contact_with_same_uid_exists_in_address_book'));
            }

            throw $exception;
        }

        $this->syncService->recordModified(ShareResourceType::AddressBook, $addressBook->id, (string) $cardUri);
        $card->fill([
            'uid' => $resourceUid,
            'etag' => $etag,
            'size' => $size,
            'data' => $normalizedData,
        ]);
        $this->mirrorService->handleSourceCardUpsert($addressBook, $card);
        $this->syncManagedContactUpsert($addressBook, $card);

        return '"'.$etag.'"';
    }

    /**
     * Deletes a card and records sync changes.
     *
     * @param  mixed  $addressBookId
     * @param  mixed  $cardUri
     */
    public function deleteCard($addressBookId, $cardUri): void
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            return;
        }

        $this->assertWritableAddressBook($addressBook);

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', $cardUri)
            ->first();

        if (! $card) {
            return;
        }

        $user = $this->davContext->getAuthenticatedUser();
        if ($this->mirrorService->deleteSourceFromMirroredCard($user, $card)) {
            return;
        }

        if ($user) {
            $queued = $this->changeRequestService->enqueueCardDavDeleteIfNeeded($user, $addressBook, $card);

            if ($queued !== null) {
                throw new Conflict(__('dav.delete_submitted_for_owner_or_admin_approval'));
            }
        }

        $this->syncManagedContactDelete($card);
        $card->delete();

        $this->syncService->recordDeleted(ShareResourceType::AddressBook, $addressBook->id, (string) $cardUri);
        $this->mirrorService->handleSourceCardDeleted($addressBook->id, (string) $cardUri);
    }

    /**
     * Returns DAV sync changes for an address book.
     *
     * @param  mixed  $addressBookId
     * @param  mixed  $syncToken
     * @param  mixed  $syncLevel
     * @param  mixed  $limit
     */
    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null): array
    {
        $addressBook = $this->loadReadableAddressBook($addressBookId);

        if ($this->isInitialSyncRequest($syncToken)) {
            return [
                'syncToken' => (string) $this->syncService->currentToken(
                    resourceType: ShareResourceType::AddressBook,
                    resourceId: $addressBook->id,
                ),
                'added' => Card::query()
                    ->where('address_book_id', $addressBook->id)
                    ->orderBy('id')
                    ->pluck('uri')
                    ->all(),
                'modified' => [],
                'deleted' => [],
            ];
        }

        return $this->syncService->getChangesSince(
            resourceType: ShareResourceType::AddressBook,
            resourceId: $addressBook->id,
            syncToken: $this->parseSyncToken($syncToken),
            limit: $limit !== null ? (int) $limit : null,
        );
    }

    /**
     * Returns transform address book.
     */
    private function transformAddressBook(AddressBook $addressBook, SharePermission $permission, string $principalUri): array
    {
        $syncToken = (string) $this->syncService->currentToken(
            resourceType: ShareResourceType::AddressBook,
            resourceId: $addressBook->id,
        );

        return [
            'id' => $addressBook->id,
            'uri' => $addressBook->uri,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $addressBook->display_name,
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $addressBook->description ?? '',
            '{http://sabredav.org/ns}sync-token' => $syncToken,
            '{http://calendarserver.org/ns/}getctag' => $syncToken,
            '{http://sabredav.org/ns}read-only' => ! $permission->canWrite(),
        ];
    }

    /**
     * Checks whether initial sync request.
     */
    private function isInitialSyncRequest(mixed $syncToken): bool
    {
        if ($syncToken === null) {
            return true;
        }

        return is_string($syncToken) && trim($syncToken) === '';
    }

    /**
     * Returns transform card.
     */
    private function transformCard(Card $card, bool $withData): array
    {
        $data = [
            'id' => $card->id,
            'uri' => $card->uri,
            'lastmodified' => $card->updated_at?->timestamp ?? time(),
            'etag' => '"'.$card->etag.'"',
            'size' => $card->size,
        ];

        if ($withData) {
            $data['carddata'] = $card->data;
        }

        return $data;
    }

    /**
     * Returns readable address book.
     */
    private function loadReadableAddressBook(int $addressBookId): AddressBook
    {
        $addressBook = AddressBook::query()->find($addressBookId);

        if (! $addressBook) {
            throw new NotFound(__('dav.address_book_not_found'));
        }

        $user = $this->davContext->getAuthenticatedUser();

        if (! $user || ! $this->accessService->userCanReadAddressBook($user, $addressBook)) {
            throw new Forbidden(__('dav.read_access_denied_for_address_book'));
        }

        return $addressBook;
    }

    /**
     * Asserts writable address book.
     */
    private function assertWritableAddressBook(AddressBook $addressBook): void
    {
        $user = $this->davContext->getAuthenticatedUser();

        if (! $user || ! $this->accessService->userCanWriteAddressBook($user, $addressBook)) {
            throw new Forbidden(__('dav.write_access_denied_for_address_book'));
        }
    }

    /**
     * Asserts deletable address book.
     */
    private function assertDeletableAddressBook(AddressBook $addressBook): void
    {
        $user = $this->davContext->getAuthenticatedUser();

        if (! $user || ! $this->accessService->userCanDeleteAddressBook($user, $addressBook)) {
            throw new Forbidden(__('dav.delete_access_denied_for_address_book'));
        }
    }

    /**
     * Parses sync token.
     */
    private function parseSyncToken(mixed $syncToken): int
    {
        if (is_int($syncToken) && $syncToken >= 0) {
            return $syncToken;
        }

        if (is_string($syncToken)) {
            $token = trim($syncToken);

            if (preg_match('/^\d+$/', $token) === 1) {
                return (int) $token;
            }
        }

        throw new InvalidSyncToken(__('dav.sync_token_format_invalid'));
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
     * Returns fallback uid for legacy payload.
     */
    private function fallbackUidForLegacyPayload(string $cardUri): string
    {
        return 'legacy-card-'.sha1($cardUri);
    }

    /**
     * Checks whether owner URI unique constraint violation.
     */
    private function isOwnerUriUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'address_books_owner_id_uri_unique')
            || str_contains($message, 'unique constraint failed: address_books.owner_id, address_books.uri');
    }

    /**
     * Checks whether uid unique constraint violation.
     */
    private function isUidUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'cards_address_book_uid_unique')
            || str_contains($message, 'unique constraint failed: cards.address_book_id, cards.uid');
    }

    /**
     * Synchronizes managed contact upsert.
     */
    private function syncManagedContactUpsert(AddressBook $addressBook, Card $card): void
    {
        try {
            $this->managedContactSync->syncCardUpsert(
                addressBook: $addressBook,
                card: $card,
                actor: $this->davContext->getAuthenticatedUser(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Synchronizes managed contact delete.
     */
    private function syncManagedContactDelete(Card $card): void
    {
        try {
            $this->managedContactSync->syncCardDeleted($card);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
