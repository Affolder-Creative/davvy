<?php

namespace App\Services\Contacts;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Contact;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\AddressBookPrivateWorkingSetService;
use App\Services\ResourceAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactService
{
    public function __construct(
        private readonly ContactVCardService $vCardService,
        private readonly ContactPhotoService $contactPhotoService,
        private readonly ResourceAccessService $accessService,
        private readonly AddressBookPrivateWorkingSetService $privateWorkingSetService,
        private readonly ContactAssignmentService $assignmentService,
        private readonly ContactRelatedNameSyncService $relatedNameSyncService,
        private readonly ContactMilestoneCalendarService $milestoneCalendarService,
    ) {}

    /**
     * Returns contacts visible to the actor.
     *
     * @return Collection<int, Contact>
     */
    public function contactsFor(User $actor): Collection
    {
        $writableAddressBookIds = $this->writableAddressBookIdsFor($actor);

        if ($writableAddressBookIds === []) {
            return collect();
        }

        return Contact::query()
            ->with(['assignments.addressBook'])
            ->whereHas('assignments', function ($query) use ($writableAddressBookIds): void {
                $query->whereIn('address_book_id', $writableAddressBookIds);
            })
            ->whereDoesntHave('assignments', function ($query) use ($writableAddressBookIds): void {
                $query->whereNotIn('address_book_id', $writableAddressBookIds);
            })
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Returns writable address books available to the actor.
     *
     * @return Collection<int, array{id:int,uri:string,display_name:string,scope:string,owner_name:?string,owner_email:?string}>
     */
    public function writableAddressBooksFor(User $actor): Collection
    {
        $hiddenSourceIds = $this->privateWorkingSetService->hiddenSourceAddressBookIdsForUser($actor);
        $privateWorkingSetBookId = $this->privateWorkingSetService->privateAddressBookIdForUser($actor);

        $owned = AddressBook::query()
            ->where('owner_id', $actor->id)
            ->when($privateWorkingSetBookId !== null, fn ($query) => $query->where('id', '!=', $privateWorkingSetBookId))
            ->orderBy('display_name')
            ->get()
            ->map(fn (AddressBook $book): array => [
                'id' => $book->id,
                'uri' => $book->uri,
                'display_name' => $book->display_name,
                'scope' => 'owned',
                'owner_name' => $actor->name,
                'owner_email' => $actor->email,
            ]);

        $shared = ResourceShare::query()
            ->with(['addressBook', 'owner'])
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('shared_with_id', $actor->id)
            ->whereIn('permission', [SharePermission::Editor->value, SharePermission::Admin->value])
            ->get()
            ->filter(fn (ResourceShare $share): bool => $share->addressBook !== null)
            ->reject(fn (ResourceShare $share): bool => in_array((int) $share->addressBook->id, $hiddenSourceIds, true))
            ->map(fn (ResourceShare $share): array => [
                'id' => $share->addressBook->id,
                'uri' => $share->addressBook->uri,
                'display_name' => $share->addressBook->display_name,
                'scope' => 'shared',
                'owner_name' => $share->owner?->name,
                'owner_email' => $share->owner?->email,
            ]);

        return $owned
            ->concat($shared)
            ->unique('id')
            ->sortBy('display_name')
            ->values();
    }

    /**
     * Returns writable address book IDs for the actor.
     *
     * @return array<int, int>
     */
    public function writableAddressBookIdsFor(User $actor): array
    {
        return $this->writableAddressBooksFor($actor)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Returns assigned address-book IDs for a contact.
     *
     * @return array<int, int>
     */
    public function addressBookIdsForContact(Contact $contact): array
    {
        return $this->assignmentService->addressBookIdsForContact($contact);
    }

    /**
     * Checks whether the actor can write all assigned address books for the contact.
     */
    public function canUserWriteContact(User $actor, Contact $contact): bool
    {
        $assignments = $contact->assignments()->with('addressBook')->get();

        if ($assignments->isEmpty()) {
            return false;
        }

        foreach ($assignments as $assignment) {
            $addressBook = $assignment->addressBook;
            if (! $addressBook || ! $this->accessService->userCanWriteAddressBook($actor, $addressBook)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates a contact, assignments, and derived milestone artifacts.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $addressBookIds
     */
    public function create(User $actor, array $payload, array $addressBookIds): Contact
    {
        $addressBooks = $this->writableAddressBookModels($actor, $addressBookIds);

        $createdResult = DB::transaction(function () use ($actor, $payload, $addressBooks): array {
            $contact = Contact::query()->create([
                'owner_id' => $actor->id,
                'uid' => (string) Str::uuid(),
                'full_name' => $this->vCardService->displayName($payload),
                'payload' => $payload,
            ]);

            $resolvedPayload = $this->contactPhotoService->preparePayloadForPersistence(
                actor: $actor,
                contact: $contact,
                incomingPayload: $payload,
            );

            $contact->update([
                'full_name' => $this->vCardService->displayName($resolvedPayload),
                'payload' => $resolvedPayload,
            ]);

            $this->assignmentService->sync($contact, $addressBooks);

            $relatedAddressBookIds = $this->syncBidirectionalRelatedNamesForContact($contact, []);

            return [
                'contact' => $contact->fresh(['assignments.addressBook']),
                'related_address_book_ids' => $relatedAddressBookIds,
            ];
        });

        $this->syncMilestoneCalendarsForAddressBooks(
            [
                ...$addressBooks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                ...(is_array($createdResult['related_address_book_ids'] ?? null)
                    ? $createdResult['related_address_book_ids']
                    : []),
            ],
        );

        return $createdResult['contact'];
    }

    /**
     * Updates a contact payload, assignments, and derived artifacts.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $addressBookIds
     */
    public function update(User $actor, Contact $contact, array $payload, array $addressBookIds): Contact
    {
        $this->assertCanMutateContact($actor, $contact);

        $payload = $this->contactPhotoService->preparePayloadForPersistence(
            actor: $actor,
            contact: $contact,
            incomingPayload: $payload,
        );

        $addressBooks = $this->writableAddressBookModels($actor, $addressBookIds);

        return $this->persistContactUpdate($contact, $payload, $addressBooks);
    }

    /**
     * Deletes a contact and cleans derived relationship artifacts.
     */
    public function delete(User $actor, Contact $contact): void
    {
        $this->assertCanMutateContact($actor, $contact);

        $this->destroyContact($contact);
    }

    /**
     * Applies an approved moderation update to a contact.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $addressBookIds
     */
    public function applyApprovedUpdate(Contact $contact, array $payload, array $addressBookIds): Contact
    {
        $payload = $this->contactPhotoService->preparePayloadForPersistence(
            actor: null,
            contact: $contact,
            incomingPayload: $payload,
        );

        $addressBooks = $this->addressBookModelsByIds($addressBookIds);

        return $this->persistContactUpdate($contact, $payload, $addressBooks);
    }

    /**
     * Applies an approved moderation delete for a contact.
     */
    public function applyApprovedDelete(Contact $contact): void
    {
        $this->destroyContact($contact);
    }

    /**
     * Asserts can mutate contact.
     */
    private function assertCanMutateContact(User $actor, Contact $contact): void
    {
        if (! $this->canUserWriteContact($actor, $contact)) {
            abort(403, __('contacts.cannot_modify_contact'));
        }
    }

    /**
     * Returns writable address book models.
     *
     * @param  array<int, int>  $addressBookIds
     * @return Collection<int, AddressBook>
     */
    private function writableAddressBookModels(User $actor, array $addressBookIds): Collection
    {
        $ids = collect($addressBookIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'address_book_ids' => [__('contacts.select_at_least_one_address_book')],
            ]);
        }

        $books = AddressBook::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $book = $books->get($id);
            if (! $book) {
                throw ValidationException::withMessages([
                    'address_book_ids' => [__('contacts.selected_address_books_not_found')],
                ]);
            }

            if (! $this->accessService->userCanWriteAddressBook($actor, $book)) {
                throw ValidationException::withMessages([
                    'address_book_ids' => [
                        __('contacts.no_write_access_to_selected_address_books'),
                    ],
                ]);
            }
        }

        return collect($ids)->map(fn (int $id): AddressBook => $books->get($id));
    }

    /**
     * Returns address book models by IDs.
     *
     * @param  array<int, int>  $addressBookIds
     * @return Collection<int, AddressBook>
     */
    private function addressBookModelsByIds(array $addressBookIds): Collection
    {
        $ids = collect($addressBookIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'address_book_ids' => [__('contacts.select_at_least_one_address_book')],
            ]);
        }

        $books = AddressBook::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $books->has($id)) {
                throw ValidationException::withMessages([
                    'address_book_ids' => [__('contacts.selected_address_books_not_found')],
                ]);
            }
        }

        return collect($ids)->map(fn (int $id): AddressBook => $books->get($id));
    }

    /**
     * Returns persist contact update.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, AddressBook>  $addressBooks
     */
    private function persistContactUpdate(Contact $contact, array $payload, Collection $addressBooks): Contact
    {
        $previousPayload = is_array($contact->payload) ? $contact->payload : [];
        $currentAddressBookIds = $this->addressBookIdsForContact($contact);

        $updatedResult = DB::transaction(function () use (
            $contact,
            $payload,
            $addressBooks,
            $previousPayload
        ): array {
            $contact->update([
                'full_name' => $this->vCardService->displayName($payload),
                'payload' => $payload,
            ]);

            $this->assignmentService->sync($contact, $addressBooks);

            $relatedAddressBookIds = $this->syncBidirectionalRelatedNamesForContact($contact, $previousPayload);

            return [
                'contact' => $contact->fresh(['assignments.addressBook']),
                'related_address_book_ids' => $relatedAddressBookIds,
            ];
        });

        $this->syncMilestoneCalendarsForAddressBooks([
            ...$currentAddressBookIds,
            ...$addressBooks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            ...(is_array($updatedResult['related_address_book_ids'] ?? null)
                ? $updatedResult['related_address_book_ids']
                : []),
        ]);

        return $updatedResult['contact'];
    }

    /**
     * Deletes contact.
     */
    private function destroyContact(Contact $contact): void
    {
        $assignedAddressBookIds = $this->addressBookIdsForContact($contact);
        $relatedAddressBookIds = [];

        DB::transaction(function () use ($contact, &$relatedAddressBookIds): void {
            $relatedAddressBookIds = $this->removeBidirectionalRelatedNamesForContact($contact);
            $payload = is_array($contact->payload) ? $contact->payload : [];
            $this->contactPhotoService->deletePhotoFromPayload($payload);

            $this->assignmentService->removeAllAssignments($contact);

            $contact->delete();
        });

        $this->syncMilestoneCalendarsForAddressBooks([
            ...$assignedAddressBookIds,
            ...$relatedAddressBookIds,
        ]);
    }

    /**
     * Synchronizes reciprocal related-name rows for a contact.
     *
     * @param  array<string, mixed>  $previousPayload
     * @return array<int, int>
     */
    public function syncBidirectionalRelatedNamesForContact(Contact $sourceContact, array $previousPayload = []): array
    {
        return $this->relatedNameSyncService->syncBidirectional($sourceContact, $previousPayload);
    }

    /**
     * Removes reciprocal related-name rows linked to a contact.
     *
     * @return array<int, int>
     */
    public function removeBidirectionalRelatedNamesForContact(Contact $contact): array
    {
        return $this->relatedNameSyncService->removeBidirectional($contact);
    }

    /**
     * Synchronizes milestone calendars for address books.
     *
     * @param  array<int, int>  $addressBookIds
     */
    private function syncMilestoneCalendarsForAddressBooks(array $addressBookIds): void
    {
        try {
            $this->milestoneCalendarService->syncAddressBooksByIds($addressBookIds);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
