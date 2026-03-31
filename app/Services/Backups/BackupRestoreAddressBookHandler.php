<?php

namespace App\Services\Backups;

use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Card;
use App\Services\Contacts\ManagedContactSyncService;
use App\Services\Dav\DavSyncService;
use App\Services\Dav\VCardValidator;
use Throwable;

class BackupRestoreAddressBookHandler
{
    public function __construct(
        private readonly BackupRestoreCollectionService $collectionService,
        private readonly BackupResourceUriService $resourceUriService,
        private readonly BackupPayloadSplitService $payloadSplitService,
        private readonly VCardValidator $vCardValidator,
        private readonly DavSyncService $syncService,
        private readonly ManagedContactSyncService $managedContactSync,
    ) {}

    /**
     * Restores a single address-book entry from the archive.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $ownerUriPool
     * @param  array<string, array<int, string>>  $cardUriPools
     * @param  array<string, int|null>  $summary
     * @param  array<int, string>  $warnings
     */
    public function restoreEntry(
        array $entry,
        int $resolvedOwnerId,
        ?string $collectionUri,
        ?string $legacyUriCandidate,
        bool $allowLegacyUriMatch,
        bool $dryRun,
        string $mode,
        array &$ownerUriPool,
        array &$cardUriPools,
        array &$summary,
        array &$warnings,
    ): void {
        $addressBook = $this->collectionService->upsertAddressBookCollection(
            ownerId: $resolvedOwnerId,
            fileStem: (string) $entry['file_stem'],
            collectionUri: $collectionUri,
            legacyUriCandidate: $legacyUriCandidate,
            allowLegacyUriMatch: $allowLegacyUriMatch,
            dryRun: $dryRun,
            mode: $mode,
            uriPool: $ownerUriPool,
            summary: $summary,
        );

        $addressBookKey = $addressBook['id'] !== null
            ? 'address-book:'.$addressBook['id']
            : 'address-book-dry-run:'.$resolvedOwnerId.':'.$addressBook['uri'];
        $addressBookModel = (! $dryRun && $addressBook['id'] !== null)
            ? AddressBook::query()->find((int) $addressBook['id'])
            : null;
        $cardUriPools[$addressBookKey] ??= $addressBook['id'] !== null
            ? Card::query()
                ->where('address_book_id', (int) $addressBook['id'])
                ->pluck('uri')
                ->map(fn (string $uri): string => trim($uri))
                ->filter()
                ->values()
                ->all()
            : [];

        try {
            $cards = $this->payloadSplitService->splitAddressBookPayload(
                payload: (string) $entry['contents'],
                archivePath: (string) $entry['archive_path'],
            );
        } catch (Throwable $throwable) {
            $warnings[] = sprintf(
                'Skipping address-book payload "%s": %s',
                $entry['archive_path'],
                $throwable->getMessage(),
            );
            $summary['resources_skipped_invalid']++;

            return;
        }

        foreach ($cards as $resource) {
            try {
                $normalized = $this->vCardValidator->validateAndNormalize(
                    (string) $resource['payload'],
                );
            } catch (Throwable $throwable) {
                $warnings[] = sprintf(
                    'Skipping invalid vCard in "%s": %s',
                    $entry['archive_path'],
                    $throwable->getMessage(),
                );
                $summary['resources_skipped_invalid']++;

                continue;
            }

            $resourceUid = $normalized['uid']
                ?? 'legacy-card-'.sha1((string) $resource['uri_candidate']);
            $existingCard = null;

            if ($addressBook['id'] !== null) {
                $existingCard = Card::query()
                    ->where('address_book_id', (int) $addressBook['id'])
                    ->where('uid', $resourceUid)
                    ->first();

                if (! $existingCard) {
                    $fallbackUri = $this->resourceUriService->normalizeResourceUri(
                        candidate: (string) $resource['uri_candidate'],
                        extension: 'vcf',
                        fallbackStem: 'card',
                    );

                    $existingCard = Card::query()
                        ->where('address_book_id', (int) $addressBook['id'])
                        ->where('uri', $fallbackUri)
                        ->first();
                }
            }

            if ($existingCard) {
                $summary['cards_updated']++;

                if (! $dryRun) {
                    $existingCard->update([
                        'uid' => $resourceUid,
                        'etag' => md5($normalized['data']),
                        'size' => strlen($normalized['data']),
                        'data' => $normalized['data'],
                    ]);

                    $this->syncService->recordModified(
                        ShareResourceType::AddressBook,
                        (int) $addressBook['id'],
                        (string) $existingCard->uri,
                    );

                    if ($addressBookModel) {
                        try {
                            $existingCard->refresh();
                            $this->managedContactSync->syncCardUpsert(
                                addressBook: $addressBookModel,
                                card: $existingCard,
                            );
                        } catch (Throwable $throwable) {
                            report($throwable);
                        }
                    }
                }

                continue;
            }

            $resourceUri = $this->resourceUriService->nextUniqueResourceUri(
                candidate: (string) $resource['uri_candidate'],
                extension: 'vcf',
                fallbackStem: 'card',
                uriPool: $cardUriPools[$addressBookKey],
            );
            $summary['cards_created']++;

            if ($dryRun || $addressBook['id'] === null) {
                continue;
            }

            $card = Card::query()->create([
                'address_book_id' => (int) $addressBook['id'],
                'uri' => $resourceUri,
                'uid' => $resourceUid,
                'etag' => md5($normalized['data']),
                'size' => strlen($normalized['data']),
                'data' => $normalized['data'],
            ]);

            $this->syncService->recordAdded(
                ShareResourceType::AddressBook,
                (int) $addressBook['id'],
                $resourceUri,
            );

            if ($addressBookModel) {
                try {
                    $this->managedContactSync->syncCardUpsert(
                        addressBook: $addressBookModel,
                        card: $card,
                    );
                } catch (Throwable $throwable) {
                    report($throwable);
                }
            }
        }
    }
}
