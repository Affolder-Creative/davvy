<?php

namespace App\Services\Contacts;

use App\Models\Contact;

class ContactRelatedNameSyncService
{
    private const RELATED_SUPPORTED_LABELS = [
        'spouse',
        'husband',
        'wife',
        'partner',
        'boyfriend',
        'girlfriend',
        'fiance',
        'fiancee',
        'parent',
        'father',
        'mother',
        'dad',
        'mom',
        'child',
        'son',
        'daughter',
        'stepson',
        'stepdaughter',
        'parent_in_law',
        'father_in_law',
        'mother_in_law',
        'child_in_law',
        'son_in_law',
        'daughter_in_law',
        'sibling',
        'brother',
        'sister',
        'sibling_in_law',
        'brother_in_law',
        'sister_in_law',
        'aunt_uncle',
        'aunt',
        'uncle',
        'niece_nephew',
        'niece',
        'nephew',
        'grandparent',
        'grandfather',
        'grandmother',
        'grandchild',
        'grandson',
        'granddaughter',
        'cousin',
        'assistant',
        'friend',
        'other',
    ];

    private const RELATED_LABEL_ALIASES = [
        'spouse' => 'spouse',
        'husband' => 'husband',
        'wife' => 'wife',
        'partner' => 'partner',
        'boyfriend' => 'boyfriend',
        'girlfriend' => 'girlfriend',
        'fiance' => 'fiance',
        'fiancee' => 'fiancee',
        'parent' => 'parent',
        'father' => 'father',
        'mother' => 'mother',
        'dad' => 'dad',
        'mom' => 'mom',
        'child' => 'child',
        'son' => 'son',
        'daughter' => 'daughter',
        'stepson' => 'stepson',
        'step son' => 'stepson',
        'stepdaughter' => 'stepdaughter',
        'step daughter' => 'stepdaughter',
        'parent in law' => 'parent_in_law',
        'father in law' => 'father_in_law',
        'mother in law' => 'mother_in_law',
        'child in law' => 'child_in_law',
        'son in law' => 'son_in_law',
        'daughter in law' => 'daughter_in_law',
        'sibling' => 'sibling',
        'brother' => 'brother',
        'sister' => 'sister',
        'sibling in law' => 'sibling_in_law',
        'brother in law' => 'brother_in_law',
        'sister in law' => 'sister_in_law',
        'aunt uncle' => 'aunt_uncle',
        'aunt or uncle' => 'aunt_uncle',
        'aunt' => 'aunt',
        'uncle' => 'uncle',
        'niece nephew' => 'niece_nephew',
        'niece or nephew' => 'niece_nephew',
        'niece' => 'niece',
        'nephew' => 'nephew',
        'grandparent' => 'grandparent',
        'grand parent' => 'grandparent',
        'grandfather' => 'grandfather',
        'grand father' => 'grandfather',
        'grandpa' => 'grandfather',
        'grand pa' => 'grandfather',
        'grandmother' => 'grandmother',
        'grand mother' => 'grandmother',
        'grandma' => 'grandmother',
        'grand ma' => 'grandmother',
        'grandchild' => 'grandchild',
        'grand child' => 'grandchild',
        'grandson' => 'grandson',
        'grand son' => 'grandson',
        'granddaughter' => 'granddaughter',
        'grand daughter' => 'granddaughter',
        'cousin' => 'cousin',
        'assistant' => 'assistant',
        'friend' => 'friend',
        'other' => 'other',
    ];

    private const RELATED_INVERSE_LABELS = [
        'spouse' => 'spouse',
        'husband' => 'spouse',
        'wife' => 'spouse',
        'partner' => 'partner',
        'boyfriend' => 'partner',
        'girlfriend' => 'partner',
        'fiance' => 'partner',
        'fiancee' => 'partner',
        'parent' => 'child',
        'father' => 'child',
        'mother' => 'child',
        'dad' => 'child',
        'mom' => 'child',
        'child' => 'parent',
        'son' => 'parent',
        'daughter' => 'parent',
        'stepson' => 'parent',
        'stepdaughter' => 'parent',
        'parent_in_law' => 'child_in_law',
        'father_in_law' => 'child_in_law',
        'mother_in_law' => 'child_in_law',
        'child_in_law' => 'parent_in_law',
        'son_in_law' => 'parent_in_law',
        'daughter_in_law' => 'parent_in_law',
        'sibling' => 'sibling',
        'brother' => 'sibling',
        'sister' => 'sibling',
        'sibling_in_law' => 'sibling_in_law',
        'brother_in_law' => 'sibling_in_law',
        'sister_in_law' => 'sibling_in_law',
        'aunt_uncle' => 'niece_nephew',
        'aunt' => 'niece_nephew',
        'uncle' => 'niece_nephew',
        'niece_nephew' => 'aunt_uncle',
        'niece' => 'aunt_uncle',
        'nephew' => 'aunt_uncle',
        'grandparent' => 'grandchild',
        'grandfather' => 'grandchild',
        'grandmother' => 'grandchild',
        'grandchild' => 'grandparent',
        'grandson' => 'grandparent',
        'granddaughter' => 'grandparent',
        'cousin' => 'cousin',
        'assistant' => 'assistant',
        'friend' => 'friend',
        'other' => 'other',
    ];

    private const RELATED_CANONICAL_LABELS = [
        'spouse' => 'spouse',
        'husband' => 'spouse',
        'wife' => 'spouse',
        'partner' => 'partner',
        'boyfriend' => 'partner',
        'girlfriend' => 'partner',
        'fiance' => 'partner',
        'fiancee' => 'partner',
        'parent' => 'parent',
        'father' => 'parent',
        'mother' => 'parent',
        'dad' => 'parent',
        'mom' => 'parent',
        'child' => 'child',
        'son' => 'child',
        'daughter' => 'child',
        'stepson' => 'child',
        'stepdaughter' => 'child',
        'parent_in_law' => 'parent_in_law',
        'father_in_law' => 'parent_in_law',
        'mother_in_law' => 'parent_in_law',
        'child_in_law' => 'child_in_law',
        'son_in_law' => 'child_in_law',
        'daughter_in_law' => 'child_in_law',
        'sibling' => 'sibling',
        'brother' => 'sibling',
        'sister' => 'sibling',
        'sibling_in_law' => 'sibling_in_law',
        'brother_in_law' => 'sibling_in_law',
        'sister_in_law' => 'sibling_in_law',
        'aunt_uncle' => 'aunt_uncle',
        'aunt' => 'aunt_uncle',
        'uncle' => 'aunt_uncle',
        'niece_nephew' => 'niece_nephew',
        'niece' => 'niece_nephew',
        'nephew' => 'niece_nephew',
        'grandparent' => 'grandparent',
        'grandfather' => 'grandparent',
        'grandmother' => 'grandparent',
        'grandchild' => 'grandchild',
        'grandson' => 'grandchild',
        'granddaughter' => 'grandchild',
        'cousin' => 'cousin',
        'assistant' => 'assistant',
        'friend' => 'friend',
        'other' => 'other',
    ];

    private const RELATED_SPOUSE_CANONICAL_LABELS = [
        'spouse',
        'partner',
    ];

    private const RELATED_SPOUSE_PROPAGATION_CANONICAL_LABELS = [
        'child_in_law',
    ];

    private const RELATED_INVERSE_GENDERED_VARIANTS = [
        'parent' => [
            'male' => 'father',
            'female' => 'mother',
        ],
        'child' => [
            'male' => 'son',
            'female' => 'daughter',
        ],
        'sibling' => [
            'male' => 'brother',
            'female' => 'sister',
        ],
        'parent_in_law' => [
            'male' => 'father_in_law',
            'female' => 'mother_in_law',
        ],
        'child_in_law' => [
            'male' => 'son_in_law',
            'female' => 'daughter_in_law',
        ],
        'sibling_in_law' => [
            'male' => 'brother_in_law',
            'female' => 'sister_in_law',
        ],
        'aunt_uncle' => [
            'male' => 'uncle',
            'female' => 'aunt',
        ],
        'niece_nephew' => [
            'male' => 'nephew',
            'female' => 'niece',
        ],
        'grandparent' => [
            'male' => 'grandfather',
            'female' => 'grandmother',
        ],
        'grandchild' => [
            'male' => 'grandson',
            'female' => 'granddaughter',
        ],
    ];

    public function __construct(
        private readonly ContactAssignmentService $assignmentService,
    ) {}

    /**
     * Synchronizes reciprocal related-name rows for a contact.
     *
     * @param  array<string, mixed>  $previousPayload
     * @return array<int, int>
     */
    public function syncBidirectional(Contact $sourceContact, array $previousPayload = []): array
    {
        $sourcePayload = is_array($sourceContact->payload) ? $sourceContact->payload : [];
        $currentRows = $this->normalizeRelatedRowsForSync($sourcePayload['related_names'] ?? []);
        $previousRows = $this->normalizeRelatedRowsForSync($previousPayload['related_names'] ?? []);
        $previousLinkedIds = $this->linkedRelatedContactIds($previousRows);
        $previousActiveLinkedRowsByContactId = $this->ownerScopedLinkedRowsByContactId($sourceContact, $previousRows);

        $linkedRowIndices = [];
        foreach ($currentRows as $index => $row) {
            $relatedContactId = $row['related_contact_id'];
            if ($relatedContactId === null || $relatedContactId <= 0) {
                continue;
            }

            if ($relatedContactId === (int) $sourceContact->id) {
                $currentRows[$index]['related_contact_id'] = null;

                continue;
            }

            if (array_key_exists($relatedContactId, $linkedRowIndices)) {
                // Keep only the first linked row per target contact; later duplicates become free-text.
                $currentRows[$index]['related_contact_id'] = null;

                continue;
            }

            $linkedRowIndices[$relatedContactId] = $index;
        }

        $targetContacts = Contact::query()
            ->whereIn('id', array_keys($linkedRowIndices))
            ->get()
            ->keyBy('id');

        foreach ($linkedRowIndices as $relatedContactId => $index) {
            /** @var Contact|null $targetContact */
            $targetContact = $targetContacts->get($relatedContactId);
            if ($targetContact === null || (int) $targetContact->owner_id !== (int) $sourceContact->owner_id) {
                $currentRows[$index]['related_contact_id'] = null;

                continue;
            }

            $targetName = trim((string) ($targetContact->full_name ?: 'Unnamed Contact'));
            if ($currentRows[$index]['value'] !== $targetName) {
                $currentRows[$index]['value'] = $targetName;
            }
        }

        $activeLinkedRowsByContactId = [];
        foreach ($currentRows as $row) {
            $relatedContactId = $row['related_contact_id'];
            if ($relatedContactId === null || $relatedContactId <= 0) {
                continue;
            }

            /** @var Contact|null $targetContact */
            $targetContact = $targetContacts->get($relatedContactId);
            if ($targetContact === null || (int) $targetContact->owner_id !== (int) $sourceContact->owner_id) {
                continue;
            }

            if (! array_key_exists($relatedContactId, $activeLinkedRowsByContactId)) {
                $activeLinkedRowsByContactId[$relatedContactId] = $row;
            }
        }

        $affectedAddressBookIds = [];
        if (! $this->relatedRowsEqual($currentRows, $sourcePayload['related_names'] ?? [])) {
            $sourcePayload['related_names'] = $currentRows;
            $sourceContact->payload = $sourcePayload;
            $sourceContact->save();

            $this->assignmentService->syncForExistingContact($sourceContact);
            $affectedAddressBookIds = [
                ...$affectedAddressBookIds,
                ...$this->assignmentService->addressBookIdsForContact($sourceContact),
            ];
        }

        $sourceDisplayName = trim((string) ($sourceContact->full_name ?: 'Unnamed Contact'));

        foreach ($activeLinkedRowsByContactId as $relatedContactId => $sourceRow) {
            /** @var Contact|null $targetContact */
            $targetContact = $targetContacts->get($relatedContactId);
            if ($targetContact === null || (int) $targetContact->owner_id !== (int) $sourceContact->owner_id) {
                continue;
            }

            $targetPayload = is_array($targetContact->payload) ? $targetContact->payload : [];
            $targetRows = $this->normalizeRelatedRowsForSync($targetPayload['related_names'] ?? []);
            $nextTargetRows = $this->upsertReciprocalRelatedRow(
                targetRows: $targetRows,
                sourceContact: $sourceContact,
                sourceRow: $sourceRow,
                sourceDisplayName: $sourceDisplayName,
            );

            if ($this->relatedRowsEqual($targetRows, $nextTargetRows)) {
                continue;
            }

            $targetPayload['related_names'] = $nextTargetRows;
            $targetContact->payload = $targetPayload;
            $targetContact->save();

            $this->assignmentService->syncForExistingContact($targetContact);
            $affectedAddressBookIds = [
                ...$affectedAddressBookIds,
                ...$this->assignmentService->addressBookIdsForContact($targetContact),
            ];
        }

        $propagationInputs = $this->spousePropagationInputsFromLinkedRows($activeLinkedRowsByContactId);
        $spouseContactIds = $propagationInputs['spouse_contact_ids'];
        $propagatedRows = $propagationInputs['propagated_rows'];

        if ($spouseContactIds !== [] && $propagatedRows !== []) {
            foreach (array_values($spouseContactIds) as $spouseContactId) {
                /** @var Contact|null $spouseContact */
                $spouseContact = $targetContacts->get($spouseContactId);
                if ($spouseContact === null || (int) $spouseContact->owner_id !== (int) $sourceContact->owner_id) {
                    continue;
                }

                $spousePayload = is_array($spouseContact->payload) ? $spouseContact->payload : [];
                $spouseRows = $this->normalizeRelatedRowsForSync($spousePayload['related_names'] ?? []);
                $spouseDisplayName = trim((string) ($spouseContact->full_name ?: 'Unnamed Contact'));

                foreach ($propagatedRows as $targetId => $propagatedSourceRow) {
                    if ($targetId === $spouseContactId || $targetId === (int) $sourceContact->id) {
                        continue;
                    }

                    /** @var Contact|null $propagatedTargetContact */
                    $propagatedTargetContact = $targetContacts->get($targetId);
                    if (
                        $propagatedTargetContact === null
                        || (int) $propagatedTargetContact->owner_id !== (int) $sourceContact->owner_id
                    ) {
                        continue;
                    }

                    $mirroredRow = [
                        'label' => $propagatedSourceRow['label'],
                        'custom_label' => $propagatedSourceRow['custom_label'],
                        'value' => $propagatedSourceRow['value'],
                        'related_contact_id' => $targetId,
                    ];

                    $nextSpouseRows = $this->upsertMirroredRelatedRow(
                        targetRows: $spouseRows,
                        incomingRow: $mirroredRow,
                    );
                    if (! $this->relatedRowsEqual($spouseRows, $nextSpouseRows)) {
                        $spouseRows = $nextSpouseRows;
                    }

                    $propagatedTargetPayload = is_array($propagatedTargetContact->payload)
                        ? $propagatedTargetContact->payload
                        : [];
                    $propagatedTargetRows = $this->normalizeRelatedRowsForSync(
                        $propagatedTargetPayload['related_names'] ?? []
                    );
                    $nextPropagatedTargetRows = $this->upsertReciprocalRelatedRow(
                        targetRows: $propagatedTargetRows,
                        sourceContact: $spouseContact,
                        sourceRow: $mirroredRow,
                        sourceDisplayName: $spouseDisplayName,
                    );

                    if ($this->relatedRowsEqual($propagatedTargetRows, $nextPropagatedTargetRows)) {
                        continue;
                    }

                    $propagatedTargetPayload['related_names'] = $nextPropagatedTargetRows;
                    $propagatedTargetContact->payload = $propagatedTargetPayload;
                    $propagatedTargetContact->save();

                    $this->assignmentService->syncForExistingContact($propagatedTargetContact);
                    $affectedAddressBookIds = [
                        ...$affectedAddressBookIds,
                        ...$this->assignmentService->addressBookIdsForContact($propagatedTargetContact),
                    ];
                }

                if ($this->relatedRowsEqual($spouseRows, $spousePayload['related_names'] ?? [])) {
                    continue;
                }

                $spousePayload['related_names'] = $spouseRows;
                $spouseContact->payload = $spousePayload;
                $spouseContact->save();

                $this->assignmentService->syncForExistingContact($spouseContact);
                $affectedAddressBookIds = [
                    ...$affectedAddressBookIds,
                    ...$this->assignmentService->addressBookIdsForContact($spouseContact),
                ];
            }
        }

        $affectedAddressBookIds = [
            ...$affectedAddressBookIds,
            ...$this->removeStaleSpousePropagationRows(
                sourceContact: $sourceContact,
                previousLinkedRowsByContactId: $previousActiveLinkedRowsByContactId,
                currentLinkedRowsByContactId: $activeLinkedRowsByContactId,
            ),
        ];

        $removedTargetIds = array_diff($previousLinkedIds, array_keys($activeLinkedRowsByContactId));
        if ($removedTargetIds !== []) {
            Contact::query()
                ->whereIn('id', $removedTargetIds)
                ->where('owner_id', $sourceContact->owner_id)
                ->get()
                ->each(function (Contact $targetContact) use (&$affectedAddressBookIds, $sourceContact): void {
                    $targetPayload = is_array($targetContact->payload) ? $targetContact->payload : [];
                    $targetRows = $this->normalizeRelatedRowsForSync($targetPayload['related_names'] ?? []);
                    $nextTargetRows = array_values(array_filter(
                        $targetRows,
                        fn (array $row): bool => $row['related_contact_id'] !== (int) $sourceContact->id
                    ));

                    if ($this->relatedRowsEqual($targetRows, $nextTargetRows)) {
                        return;
                    }

                    $targetPayload['related_names'] = $nextTargetRows;
                    $targetContact->payload = $targetPayload;
                    $targetContact->save();

                    $this->assignmentService->syncForExistingContact($targetContact);
                    $affectedAddressBookIds = [
                        ...$affectedAddressBookIds,
                        ...$this->assignmentService->addressBookIdsForContact($targetContact),
                    ];
                });
        }

        return collect($affectedAddressBookIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Removes reciprocal related-name rows linked to a contact.
     *
     * @return array<int, int>
     */
    public function removeBidirectional(Contact $contact): array
    {
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $rows = $this->normalizeRelatedRowsForSync($payload['related_names'] ?? []);
        $linkedIds = $this->linkedRelatedContactIds($rows);
        $activeLinkedRowsByContactId = $this->ownerScopedLinkedRowsByContactId($contact, $rows);

        if ($linkedIds === []) {
            return [];
        }

        $affectedAddressBookIds = $this->removeStaleSpousePropagationRows(
            sourceContact: $contact,
            previousLinkedRowsByContactId: $activeLinkedRowsByContactId,
            currentLinkedRowsByContactId: [],
        );

        Contact::query()
            ->whereIn('id', $linkedIds)
            ->where('owner_id', $contact->owner_id)
            ->get()
            ->each(function (Contact $targetContact) use (&$affectedAddressBookIds, $contact): void {
                $targetPayload = is_array($targetContact->payload) ? $targetContact->payload : [];
                $targetRows = $this->normalizeRelatedRowsForSync($targetPayload['related_names'] ?? []);
                $nextTargetRows = array_values(array_filter(
                    $targetRows,
                    fn (array $row): bool => $row['related_contact_id'] !== (int) $contact->id
                ));

                if ($this->relatedRowsEqual($targetRows, $nextTargetRows)) {
                    return;
                }

                $targetPayload['related_names'] = $nextTargetRows;
                $targetContact->payload = $targetPayload;
                $targetContact->save();

                $this->assignmentService->syncForExistingContact($targetContact);
                $affectedAddressBookIds = [
                    ...$affectedAddressBookIds,
                    ...$this->assignmentService->addressBookIdsForContact($targetContact),
                ];
            });

        return collect($affectedAddressBookIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalizes related rows for sync.
     *
     * @return array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>
     */
    private function normalizeRelatedRowsForSync(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row): array {
                return [
                    'label' => strtolower($this->normalizeString($row['label'] ?? null) ?? 'other'),
                    'custom_label' => $this->normalizeString($row['custom_label'] ?? null),
                    'value' => $this->normalizeString($row['value'] ?? null) ?? '',
                    'related_contact_id' => $this->normalizeInt($row['related_contact_id'] ?? null),
                ];
            })
            ->filter(fn (array $row): bool => $row['value'] !== '' || $row['related_contact_id'] !== null)
            ->values()
            ->all();
    }

    /**
     * Returns linked related contact IDs.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $rows
     * @return array<int, int>
     */
    private function linkedRelatedContactIds(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): ?int => $row['related_contact_id'])
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Returns owner scoped linked rows by contact ID.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $rows
     * @return array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>
     */
    private function ownerScopedLinkedRowsByContactId(Contact $sourceContact, array $rows): array
    {
        $linkedRowsByContactId = [];

        foreach ($rows as $row) {
            $relatedContactId = $row['related_contact_id'];
            if ($relatedContactId === null || $relatedContactId <= 0) {
                continue;
            }

            if ($relatedContactId === (int) $sourceContact->id) {
                continue;
            }

            if (! array_key_exists($relatedContactId, $linkedRowsByContactId)) {
                $linkedRowsByContactId[$relatedContactId] = $row;
            }
        }

        if ($linkedRowsByContactId === []) {
            return [];
        }

        $allowedIds = Contact::query()
            ->whereIn('id', array_keys($linkedRowsByContactId))
            ->where('owner_id', $sourceContact->owner_id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($allowedIds === []) {
            return [];
        }

        return array_intersect_key($linkedRowsByContactId, array_flip($allowedIds));
    }

    /**
     * Returns spouse propagation inputs from linked rows.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $linkedRowsByContactId
     * @return array{
     *   spouse_contact_ids:array<int, int>,
     *   propagated_rows:array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>
     * }
     */
    private function spousePropagationInputsFromLinkedRows(array $linkedRowsByContactId): array
    {
        $spouseContactIds = [];
        $propagatedRows = [];

        foreach ($linkedRowsByContactId as $relatedContactId => $sourceRow) {
            $canonical = $this->canonicalRelatedLabelForRow($sourceRow);
            if ($canonical === null) {
                continue;
            }

            if (in_array($canonical, self::RELATED_SPOUSE_CANONICAL_LABELS, true)) {
                $spouseContactIds[(int) $relatedContactId] = (int) $relatedContactId;
            }

            if (in_array($canonical, self::RELATED_SPOUSE_PROPAGATION_CANONICAL_LABELS, true)) {
                $propagatedRows[(int) $relatedContactId] = $sourceRow;
            }
        }

        return [
            'spouse_contact_ids' => $spouseContactIds,
            'propagated_rows' => $propagatedRows,
        ];
    }

    /**
     * Returns spouse propagation pairs.
     *
     * @param  array<int, int>  $spouseContactIds
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $propagatedRows
     * @return array<string, array{
     *   spouse_contact_id:int,
     *   target_contact_id:int,
     *   source_row:array{label:string, custom_label:?string, value:string, related_contact_id:?int}
     * }>
     */
    private function spousePropagationPairs(
        int $sourceContactId,
        array $spouseContactIds,
        array $propagatedRows,
    ): array {
        $pairs = [];

        foreach (array_values($spouseContactIds) as $spouseContactId) {
            foreach ($propagatedRows as $targetContactId => $sourceRow) {
                $targetContactId = (int) $targetContactId;

                if ($targetContactId === $spouseContactId || $targetContactId === $sourceContactId) {
                    continue;
                }

                $pairKey = $spouseContactId.':'.$targetContactId;
                if (! array_key_exists($pairKey, $pairs)) {
                    $pairs[$pairKey] = [
                        'spouse_contact_id' => $spouseContactId,
                        'target_contact_id' => $targetContactId,
                        'source_row' => $sourceRow,
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * Removes stale spouse propagation rows.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $previousLinkedRowsByContactId
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $currentLinkedRowsByContactId
     * @return array<int, int>
     */
    private function removeStaleSpousePropagationRows(
        Contact $sourceContact,
        array $previousLinkedRowsByContactId,
        array $currentLinkedRowsByContactId,
    ): array {
        $previousInputs = $this->spousePropagationInputsFromLinkedRows($previousLinkedRowsByContactId);
        $currentInputs = $this->spousePropagationInputsFromLinkedRows($currentLinkedRowsByContactId);

        $previousPairs = $this->spousePropagationPairs(
            sourceContactId: (int) $sourceContact->id,
            spouseContactIds: $previousInputs['spouse_contact_ids'],
            propagatedRows: $previousInputs['propagated_rows'],
        );
        if ($previousPairs === []) {
            return [];
        }

        $currentPairs = $this->spousePropagationPairs(
            sourceContactId: (int) $sourceContact->id,
            spouseContactIds: $currentInputs['spouse_contact_ids'],
            propagatedRows: $currentInputs['propagated_rows'],
        );

        $stalePairKeys = array_diff(array_keys($previousPairs), array_keys($currentPairs));
        if ($stalePairKeys === []) {
            return [];
        }

        $stalePairs = collect($stalePairKeys)
            ->map(fn (string $pairKey): array => $previousPairs[$pairKey])
            ->values()
            ->all();

        $contactsById = Contact::query()
            ->whereIn(
                'id',
                collect($stalePairs)
                    ->flatMap(fn (array $pair): array => [
                        (int) $pair['spouse_contact_id'],
                        (int) $pair['target_contact_id'],
                    ])
                    ->unique()
                    ->values()
                    ->all(),
            )
            ->where('owner_id', $sourceContact->owner_id)
            ->get()
            ->keyBy('id');

        $affectedAddressBookIds = [];

        foreach ($stalePairs as $pair) {
            $spouseContactId = (int) $pair['spouse_contact_id'];
            $targetContactId = (int) $pair['target_contact_id'];
            $sourceRow = $pair['source_row'];

            /** @var Contact|null $spouseContact */
            $spouseContact = $contactsById->get($spouseContactId);
            /** @var Contact|null $targetContact */
            $targetContact = $contactsById->get($targetContactId);

            $expectedSpouseCanonical = $this->canonicalRelatedLabelForRow($sourceRow);
            if ($spouseContact !== null && $expectedSpouseCanonical !== null) {
                $this->removeRelatedRowsForDerivedPair(
                    $spouseContact,
                    $targetContactId,
                    $expectedSpouseCanonical,
                    $affectedAddressBookIds,
                );
            }

            $expectedTargetCanonical = $this->inverseCanonicalForPropagationRow(
                sourceRow: $sourceRow,
                spouseContact: $spouseContact,
                targetContactId: $targetContactId,
            );
            if ($targetContact !== null && $expectedTargetCanonical !== null) {
                $this->removeRelatedRowsForDerivedPair(
                    $targetContact,
                    $spouseContactId,
                    $expectedTargetCanonical,
                    $affectedAddressBookIds,
                );
            }
        }

        return collect($affectedAddressBookIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Returns inverse canonical for propagation row.
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $sourceRow
     */
    private function inverseCanonicalForPropagationRow(
        array $sourceRow,
        ?Contact $spouseContact,
        int $targetContactId,
    ): ?string {
        $mirroredRow = [
            'label' => $sourceRow['label'],
            'custom_label' => $sourceRow['custom_label'],
            'value' => $sourceRow['value'],
            'related_contact_id' => $targetContactId,
        ];

        if ($spouseContact !== null) {
            $inverse = $this->inverseLabelForRelatedRow($spouseContact, $mirroredRow);

            return $this->canonicalRelatedLabelForRow([
                'label' => $inverse['label'],
                'custom_label' => $inverse['custom_label'],
                'value' => '',
                'related_contact_id' => (int) $spouseContact->id,
            ]);
        }

        [$token] = $this->relatedLabelTokenAndDisplay($mirroredRow);
        if ($token === null) {
            return null;
        }

        $inverseToken = self::RELATED_INVERSE_LABELS[$token] ?? $token;

        return self::RELATED_CANONICAL_LABELS[$inverseToken] ?? null;
    }

    /**
     * Removes related rows for derived pair.
     *
     * @param  array<int, int>  $affectedAddressBookIds
     */
    private function removeRelatedRowsForDerivedPair(
        Contact $contact,
        int $relatedContactId,
        string $expectedCanonicalLabel,
        array &$affectedAddressBookIds,
    ): void {
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $rows = $this->normalizeRelatedRowsForSync($payload['related_names'] ?? []);
        $nextRows = array_values(array_filter($rows, function (array $row) use (
            $relatedContactId,
            $expectedCanonicalLabel
        ): bool {
            if (($row['related_contact_id'] ?? null) !== $relatedContactId) {
                return true;
            }

            $rowCanonical = $this->canonicalRelatedLabelForRow($row);

            return $rowCanonical !== $expectedCanonicalLabel;
        }));

        if ($this->relatedRowsEqual($rows, $nextRows)) {
            return;
        }

        $payload['related_names'] = $nextRows;
        $contact->payload = $payload;
        $contact->save();

        $this->assignmentService->syncForExistingContact($contact);
        $affectedAddressBookIds = [
            ...$affectedAddressBookIds,
            ...$this->assignmentService->addressBookIdsForContact($contact),
        ];
    }

    /**
     * Returns upsert reciprocal related row.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $targetRows
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $sourceRow
     * @return array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>
     */
    private function upsertReciprocalRelatedRow(
        array $targetRows,
        Contact $sourceContact,
        array $sourceRow,
        string $sourceDisplayName,
    ): array {
        $inverse = $this->inverseLabelForRelatedRow(
            sourceContact: $sourceContact,
            row: $sourceRow,
        );
        $reciprocalRow = [
            'label' => $inverse['label'],
            'custom_label' => $inverse['custom_label'],
            'value' => $sourceDisplayName,
            'related_contact_id' => (int) $sourceContact->id,
        ];

        $matchingIndices = collect($targetRows)
            ->map(fn (array $row, int $index): array => ['row' => $row, 'index' => $index])
            ->filter(fn (array $item): bool => ($item['row']['related_contact_id'] ?? null) === (int) $sourceContact->id)
            ->pluck('index')
            ->values()
            ->all();

        if ($matchingIndices === []) {
            $targetRows[] = $reciprocalRow;

            return array_values($targetRows);
        }

        $primaryIndex = (int) $matchingIndices[0];
        $existingRow = $targetRows[$primaryIndex];
        $targetRows[$primaryIndex] = $this->mergedReciprocalRow(
            existingRow: $existingRow,
            incomingRow: $reciprocalRow,
        );

        $duplicateIndices = array_slice($matchingIndices, 1);
        if ($duplicateIndices !== []) {
            $targetRows = collect($targetRows)
                ->reject(fn (array $row, int $index): bool => in_array($index, $duplicateIndices, true))
                ->values()
                ->all();
        }

        return array_values($targetRows);
    }

    /**
     * Returns merged reciprocal row.
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $existingRow
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $incomingRow
     * @return array{label:string, custom_label:?string, value:string, related_contact_id:?int}
     */
    private function mergedReciprocalRow(array $existingRow, array $incomingRow): array
    {
        [$existingToken] = $this->relatedLabelTokenAndDisplay($existingRow);
        [$incomingToken] = $this->relatedLabelTokenAndDisplay($incomingRow);

        if ($this->shouldPreserveExistingWhenIncomingIsOther($existingRow, $existingToken, $incomingToken)) {
            return [
                'label' => $existingRow['label'],
                'custom_label' => $existingRow['custom_label'],
                'value' => $incomingRow['value'],
                'related_contact_id' => $incomingRow['related_contact_id'],
            ];
        }

        if (! $this->shouldPreserveExistingSpecificReciprocalLabel($existingToken, $incomingToken)) {
            return $incomingRow;
        }

        return [
            'label' => $existingRow['label'],
            'custom_label' => $existingRow['custom_label'],
            'value' => $incomingRow['value'],
            'related_contact_id' => $incomingRow['related_contact_id'],
        ];
    }

    /**
     * Checks whether it should preserve existing specific reciprocal label.
     */
    private function shouldPreserveExistingSpecificReciprocalLabel(
        ?string $existingToken,
        ?string $incomingToken,
    ): bool {
        if ($existingToken === null || $incomingToken === null) {
            return false;
        }

        $existingCanonical = self::RELATED_CANONICAL_LABELS[$existingToken] ?? null;
        $incomingCanonical = self::RELATED_CANONICAL_LABELS[$incomingToken] ?? null;
        if ($existingCanonical === null || $incomingCanonical === null) {
            return false;
        }

        if ($existingCanonical !== $incomingCanonical) {
            return false;
        }

        $incomingIsGeneric = $incomingToken === $incomingCanonical;
        $existingIsSpecific = $existingToken !== $existingCanonical;

        return $incomingIsGeneric && $existingIsSpecific;
    }

    /**
     * Preserve richer reciprocal labels when the incoming update is only "other".
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $existingRow
     */
    private function shouldPreserveExistingWhenIncomingIsOther(
        array $existingRow,
        ?string $existingToken,
        ?string $incomingToken,
    ): bool {
        if ($incomingToken !== 'other') {
            return false;
        }

        if ($existingToken !== null && $existingToken !== 'other') {
            return true;
        }

        $existingLabel = strtolower($this->normalizeString($existingRow['label'] ?? null) ?? 'other');
        if ($existingLabel !== 'custom') {
            return false;
        }

        $customLabel = $this->normalizeString($existingRow['custom_label'] ?? null);
        if ($customLabel === null) {
            return false;
        }

        $customToken = $this->normalizeRelatedLabelToken($customLabel);

        return $customToken !== 'other';
    }

    /**
     * Returns gender aware inverse token.
     */
    private function genderAwareInverseToken(string $inverseToken, Contact $sourceContact): string
    {
        $variants = self::RELATED_INVERSE_GENDERED_VARIANTS[$inverseToken] ?? null;
        if (! is_array($variants)) {
            return $inverseToken;
        }

        $gender = $this->inferredGenderForContact($sourceContact);
        if ($gender === null) {
            return $inverseToken;
        }

        return $variants[$gender] ?? $inverseToken;
    }

    /**
     * Returns inferred gender for contact.
     */
    private function inferredGenderForContact(Contact $contact): ?string
    {
        $payload = is_array($contact->payload) ? $contact->payload : [];
        $pronouns = $this->normalizeString($payload['pronouns_custom'] ?? null)
            ?? $this->normalizeString($payload['pronouns'] ?? null);

        return $this->inferredGenderFromPronouns($pronouns);
    }

    /**
     * Returns inferred gender from pronouns.
     */
    private function inferredGenderFromPronouns(?string $value): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        $tokens = preg_split('/[^a-z]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens) || $tokens === []) {
            return null;
        }

        $tokenSet = array_flip($tokens);
        $hasMale = isset($tokenSet['he']) || isset($tokenSet['him']) || isset($tokenSet['his']);
        $hasFemale = isset($tokenSet['she']) || isset($tokenSet['her']) || isset($tokenSet['hers']);

        if ($hasMale && ! $hasFemale) {
            return 'male';
        }

        if ($hasFemale && ! $hasMale) {
            return 'female';
        }

        return null;
    }

    /**
     * Returns canonical related label for row.
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $row
     */
    private function canonicalRelatedLabelForRow(array $row): ?string
    {
        [$token] = $this->relatedLabelTokenAndDisplay($row);
        if ($token === null) {
            return null;
        }

        return self::RELATED_CANONICAL_LABELS[$token] ?? null;
    }

    /**
     * Returns upsert mirrored related row.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $targetRows
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $incomingRow
     * @return array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>
     */
    private function upsertMirroredRelatedRow(array $targetRows, array $incomingRow): array
    {
        $incomingRelatedContactId = $incomingRow['related_contact_id'] ?? null;
        if ($incomingRelatedContactId === null || $incomingRelatedContactId <= 0) {
            return array_values($targetRows);
        }

        $matchingIndices = collect($targetRows)
            ->map(fn (array $row, int $index): array => ['row' => $row, 'index' => $index])
            ->filter(fn (array $item): bool => ($item['row']['related_contact_id'] ?? null) === $incomingRelatedContactId)
            ->pluck('index')
            ->values()
            ->all();

        if ($matchingIndices === []) {
            $targetRows[] = $incomingRow;

            return array_values($targetRows);
        }

        $primaryIndex = (int) $matchingIndices[0];
        $existingRow = $targetRows[$primaryIndex];
        $targetRows[$primaryIndex] = $this->mergedReciprocalRow(
            existingRow: $existingRow,
            incomingRow: $incomingRow,
        );

        $duplicateIndices = array_slice($matchingIndices, 1);
        if ($duplicateIndices !== []) {
            $targetRows = collect($targetRows)
                ->reject(fn (array $row, int $index): bool => in_array($index, $duplicateIndices, true))
                ->values()
                ->all();
        }

        return array_values($targetRows);
    }

    /**
     * Returns inverse label for related row.
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $row
     * @return array{label:string, custom_label:?string}
     */
    private function inverseLabelForRelatedRow(Contact $sourceContact, array $row): array
    {
        [$token, $displayLabel] = $this->relatedLabelTokenAndDisplay($row);
        if ($token === null) {
            return [
                'label' => 'custom',
                'custom_label' => $displayLabel,
            ];
        }

        $inverseToken = self::RELATED_INVERSE_LABELS[$token] ?? $token;
        $inverseToken = $this->genderAwareInverseToken($inverseToken, $sourceContact);
        if (in_array($inverseToken, self::RELATED_SUPPORTED_LABELS, true)) {
            return [
                'label' => $inverseToken,
                'custom_label' => null,
            ];
        }

        return [
            'label' => 'custom',
            'custom_label' => $displayLabel,
        ];
    }

    /**
     * Returns related label token and display.
     *
     * @param  array{label:string, custom_label:?string, value:string, related_contact_id:?int}  $row
     * @return array{0:?string,1:?string}
     */
    private function relatedLabelTokenAndDisplay(array $row): array
    {
        $label = strtolower($this->normalizeString($row['label'] ?? null) ?? 'other');
        if ($label === 'custom') {
            $customLabel = $this->normalizeString($row['custom_label'] ?? null);
            if ($customLabel === null) {
                return [null, null];
            }

            return [
                $this->normalizeRelatedLabelToken($customLabel),
                $customLabel,
            ];
        }

        return [
            $this->normalizeRelatedLabelToken($label) ?? $label,
            $label,
        ];
    }

    /**
     * Normalizes related label token.
     */
    private function normalizeRelatedLabelToken(?string $value): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['_', '-', '/', '&'], ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
        if ($normalized === '') {
            return null;
        }

        return self::RELATED_LABEL_ALIASES[$normalized] ?? null;
    }

    /**
     * Checks whether related rows equal.
     *
     * @param  array<int, array{label:string, custom_label:?string, value:string, related_contact_id:?int}>  $leftRows
     */
    private function relatedRowsEqual(array $leftRows, mixed $rightRows): bool
    {
        return $leftRows === $this->normalizeRelatedRowsForSync($rightRows);
    }

    /**
     * Normalizes string.
     */
    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Normalizes int.
     */
    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
