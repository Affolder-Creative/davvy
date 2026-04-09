<?php

namespace App\Services\Contacts;

use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Card;
use App\Models\Contact;
use App\Models\ContactAddressBookAssignment;
use App\Services\AddressBookMirrorService;
use App\Services\AddressBookPrivateWorkingSetService;
use App\Services\Dav\DavSyncService;
use App\Services\Dav\VCardValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactAssignmentService
{
    public function __construct(
        private readonly ContactVCardService $vCardService,
        private readonly VCardValidator $vCardValidator,
        private readonly DavSyncService $syncService,
        private readonly AddressBookMirrorService $mirrorService,
        private readonly AddressBookPrivateWorkingSetService $privateWorkingSetService,
    ) {}

    /**
     * Returns assigned address-book IDs for a contact.
     *
     * @return array<int, int>
     */
    public function addressBookIdsForContact(Contact $contact): array
    {
        return $contact->assignments()
            ->pluck('address_book_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Synchronizes assignments.
     *
     * @param  Collection<int, AddressBook>  $addressBooks
     */
    public function sync(Contact $contact, Collection $addressBooks): void
    {
        $cardData = $this->normalizedCardData($contact);

        $existing = $contact->assignments()
            ->with(['card', 'addressBook'])
            ->get()
            ->keyBy('address_book_id');

        $desired = $addressBooks->keyBy('id');

        foreach ($existing as $addressBookId => $assignment) {
            if (! $desired->has($addressBookId)) {
                $this->deleteAssignmentCard($assignment);
                $assignment->delete();
            }
        }

        foreach ($addressBooks as $addressBook) {
            /** @var ContactAddressBookAssignment|null $assignment */
            $assignment = $existing->get($addressBook->id);

            if (! $assignment) {
                $this->createAssignment($contact, $addressBook, $cardData);

                continue;
            }

            $this->upsertAssignmentCard($contact, $addressBook, $assignment, $cardData);
        }
    }

    /**
     * Synchronizes assignments for existing contact.
     */
    public function syncForExistingContact(Contact $contact): void
    {
        $addressBookIds = $this->addressBookIdsForContact($contact);
        if ($addressBookIds === []) {
            return;
        }

        $addressBooksById = AddressBook::query()
            ->whereIn('id', $addressBookIds)
            ->get()
            ->keyBy('id');

        $orderedAddressBooks = collect($addressBookIds)
            ->map(fn (int $id): ?AddressBook => $addressBooksById->get($id))
            ->filter(fn (?AddressBook $book): bool => $book !== null)
            ->values();

        if ($orderedAddressBooks->isEmpty()) {
            return;
        }

        $this->sync($contact, $orderedAddressBooks);
    }

    /**
     * Removes all assignments (and backing cards) for a contact.
     */
    public function removeAllAssignments(Contact $contact): void
    {
        $assignments = $contact->assignments()->with(['card', 'addressBook'])->get();

        foreach ($assignments as $assignment) {
            $this->deleteAssignmentCard($assignment);
            $assignment->delete();
        }
    }

    /**
     * Creates assignment.
     */
    private function createAssignment(Contact $contact, AddressBook $addressBook, string $cardData): void
    {
        $this->assertNoUidConflict($addressBook, $contact->uid);

        $uri = $this->nextAvailableCardUri($addressBook, $contact, null, null);
        $etag = md5($cardData);

        $card = Card::query()->create([
            'address_book_id' => $addressBook->id,
            'uri' => $uri,
            'uid' => $contact->uid,
            'etag' => $etag,
            'size' => strlen($cardData),
            'data' => $cardData,
        ]);

        $this->syncService->recordAdded(ShareResourceType::AddressBook, $addressBook->id, $card->uri);
        $this->mirrorService->handleSourceCardUpsert($addressBook, $card);
        $this->privateWorkingSetService->handleSourceCardUpsert($addressBook, $card);
        $this->privateWorkingSetService->handlePrivateCardUpsert($card);

        $contact->assignments()->create([
            'address_book_id' => $addressBook->id,
            'card_id' => $card->id,
            'card_uri' => $card->uri,
        ]);
    }

    /**
     * Performs the upsert assignment card operation.
     */
    private function upsertAssignmentCard(
        Contact $contact,
        AddressBook $addressBook,
        ContactAddressBookAssignment $assignment,
        string $cardData,
    ): void {
        $card = $assignment->card;
        if (! $card) {
            $this->assertNoUidConflict($addressBook, $contact->uid);

            $preferredUri = $assignment->card_uri !== '' ? $assignment->card_uri : null;
            $uri = $this->nextAvailableCardUri($addressBook, $contact, $preferredUri, null);
            $etag = md5($cardData);

            $card = Card::query()->create([
                'address_book_id' => $addressBook->id,
                'uri' => $uri,
                'uid' => $contact->uid,
                'etag' => $etag,
                'size' => strlen($cardData),
                'data' => $cardData,
            ]);

            $assignment->update([
                'card_id' => $card->id,
                'card_uri' => $card->uri,
            ]);

            $this->syncService->recordAdded(ShareResourceType::AddressBook, $addressBook->id, $card->uri);
            $this->mirrorService->handleSourceCardUpsert($addressBook, $card);
            $this->privateWorkingSetService->handleSourceCardUpsert($addressBook, $card);
            $this->privateWorkingSetService->handlePrivateCardUpsert($card);

            return;
        }

        $this->assertNoUidConflict($addressBook, $contact->uid, $card->id);

        $size = strlen($cardData);
        $etag = md5($cardData);
        $isNoOp = $card->uid === $contact->uid
            && $card->etag === $etag
            && (int) $card->size === $size
            && $card->data === $cardData;

        if (! $isNoOp) {
            $card->update([
                'uid' => $contact->uid,
                'etag' => $etag,
                'size' => $size,
                'data' => $cardData,
            ]);

            $this->syncService->recordModified(ShareResourceType::AddressBook, $addressBook->id, $card->uri);
            $card->fill([
                'uid' => $contact->uid,
                'etag' => $etag,
                'size' => $size,
                'data' => $cardData,
            ]);
            $this->mirrorService->handleSourceCardUpsert($addressBook, $card);
            $this->privateWorkingSetService->handleSourceCardUpsert($addressBook, $card);
            $this->privateWorkingSetService->handlePrivateCardUpsert($card);
        }

        if ($assignment->card_uri !== $card->uri) {
            $assignment->update([
                'card_uri' => $card->uri,
            ]);
        }
    }

    /**
     * Deletes assignment card.
     */
    private function deleteAssignmentCard(ContactAddressBookAssignment $assignment): void
    {
        $card = $assignment->card;
        if (! $card) {
            return;
        }

        $card->delete();

        $this->syncService->recordDeleted(ShareResourceType::AddressBook, $assignment->address_book_id, $card->uri);
        $this->mirrorService->handleSourceCardDeleted($assignment->address_book_id, $card->uri);
        $this->privateWorkingSetService->handleSourceCardDeleted($assignment->address_book_id, $card->uri);
        $this->privateWorkingSetService->handlePrivateCardDeleted($card);
    }

    /**
     * Asserts no uid conflict.
     */
    private function assertNoUidConflict(AddressBook $addressBook, string $uid, ?int $exceptCardId = null): void
    {
        $query = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uid', $uid);

        if ($exceptCardId !== null) {
            $query->where('id', '!=', $exceptCardId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'address_book_ids' => [
                    __('contacts.contact_with_uid_already_exists_in_address_book', [
                        'uid' => $uid,
                        'address_book' => $addressBook->display_name,
                    ]),
                ],
            ]);
        }
    }

    /**
     * Returns normalized card data.
     */
    private function normalizedCardData(Contact $contact): string
    {
        $raw = $this->vCardService->build($contact);
        $normalized = $this->vCardValidator->validateAndNormalize($raw);

        return $normalized['data'];
    }

    /**
     * Returns next available card URI.
     */
    private function nextAvailableCardUri(
        AddressBook $addressBook,
        Contact $contact,
        ?string $preferredUri,
        ?int $exceptCardId,
    ): string {
        $candidate = $this->sanitizeCardUri($preferredUri);

        if ($candidate !== null && ! $this->cardUriExists($addressBook->id, $candidate, $exceptCardId)) {
            return $candidate;
        }

        $base = Str::slug($contact->full_name ?? '') ?: 'contact';
        $base .= '-'.substr(sha1($contact->uid), 0, 8);

        $attempt = 0;
        do {
            $suffix = $attempt === 0 ? '' : '-'.$attempt;
            $candidate = $base.$suffix.'.vcf';
            $attempt++;
        } while ($this->cardUriExists($addressBook->id, $candidate, $exceptCardId));

        return $candidate;
    }

    /**
     * Returns sanitize card URI.
     */
    private function sanitizeCardUri(?string $value): ?string
    {
        $uri = trim((string) ($value ?? ''));
        if ($uri === '') {
            return null;
        }

        $uri = preg_replace('/\s+/', '-', $uri) ?? '';
        $uri = trim($uri);
        if ($uri === '') {
            return null;
        }

        return str_ends_with(strtolower($uri), '.vcf') ? $uri : $uri.'.vcf';
    }

    /**
     * Checks whether card URI exists.
     */
    private function cardUriExists(int $addressBookId, string $uri, ?int $exceptCardId): bool
    {
        $query = Card::query()
            ->where('address_book_id', $addressBookId)
            ->where('uri', $uri);

        if ($exceptCardId !== null) {
            $query->where('id', '!=', $exceptCardId);
        }

        return $query->exists();
    }
}
