<?php

namespace App\Services\Backups;

use App\Models\AddressBook;
use App\Models\Calendar;
use App\Models\CalendarObject;
use App\Models\Card;
use App\Models\User;
use App\Services\ResourceDeletionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackupRestoreService
{
    public function __construct(
        private readonly ResourceDeletionService $resourceDeletion,
        private readonly BackupArchiveReader $archiveReader,
        private readonly BackupRestoreCollectionService $collectionService,
        private readonly BackupRestoreCalendarHandler $calendarHandler,
        private readonly BackupRestoreAddressBookHandler $addressBookHandler,
    ) {}

    /**
     * Restores application resources from a backup archive.
     *
     * @return array{
     *   status:'success',
     *   trigger:string,
     *   mode:'merge'|'replace',
     *   dry_run:bool,
     *   reason:string,
     *   executed_at_utc:string,
     *   manifest:array<string, mixed>|null,
     *   summary:array<string, int|null>,
     *   warnings:array<int, string>
     * }
     */
    public function restoreFromArchive(
        string $archivePath,
        string $mode = 'merge',
        bool $dryRun = false,
        ?int $fallbackOwnerId = null,
        string $trigger = 'manual-cli',
    ): array {
        $normalizedMode = in_array($mode, ['merge', 'replace'], true) ? $mode : null;
        if ($normalizedMode === null) {
            throw new RuntimeException(__('backups.restore_mode_must_be_merge_or_replace'));
        }

        if (! is_file($archivePath)) {
            throw new RuntimeException(__('backups.backup_archive_file_not_found'));
        }

        $fallbackOwner = null;
        if ($fallbackOwnerId !== null) {
            $fallbackOwner = User::query()->find($fallbackOwnerId);
            if (! $fallbackOwner) {
                throw new RuntimeException(__('backups.fallback_owner_user_id_does_not_exist'));
            }
        }

        $warnings = [];
        [$entries, $manifest, $ownerIdsInArchive] = $this->archiveReader->readArchiveEntries(
            archivePath: $archivePath,
            warnings: $warnings,
        );

        if ($entries === []) {
            throw new RuntimeException(__('backups.backup_archive_contains_no_restorable_resources'));
        }

        /** @var array<int, int> $ownerResolution */
        $ownerResolution = [];
        $missingOwners = [];
        foreach ($ownerIdsInArchive as $ownerId) {
            $ownerExists = User::query()->whereKey($ownerId)->exists();

            if ($ownerExists) {
                $ownerResolution[$ownerId] = $ownerId;

                continue;
            }

            if ($fallbackOwner !== null) {
                $ownerResolution[$ownerId] = (int) $fallbackOwner->id;

                continue;
            }

            $missingOwners[] = $ownerId;
            $warnings[] = __('backups.skipping_resources_for_missing_backup_owner_id', ['owner_id' => $ownerId]);
        }

        $processableEntries = collect($entries)
            ->filter(function (array $entry) use ($ownerResolution): bool {
                return isset($ownerResolution[(int) $entry['owner_id']]);
            })
            ->map(function (array $entry) use ($ownerResolution): array {
                $entry['resolved_owner_id'] = $ownerResolution[(int) $entry['owner_id']];

                return $entry;
            })
            ->values()
            ->all();

        if ($processableEntries === []) {
            throw new RuntimeException(__('backups.no_resources_can_be_restored_all_owners_unresolved'));
        }

        $summary = [
            'files_total' => count($entries),
            'files_processed' => 0,
            'files_skipped' => count($entries) - count($processableEntries),
            'owners_total' => count($ownerIdsInArchive),
            'owners_resolved' => count($ownerResolution),
            'owners_missing' => count($missingOwners),
            'fallback_owner_id' => $fallbackOwner?->id,
            'calendars_created' => 0,
            'calendars_updated' => 0,
            'calendars_deleted' => 0,
            'calendar_objects_created' => 0,
            'calendar_objects_updated' => 0,
            'calendar_objects_deleted' => 0,
            'address_books_created' => 0,
            'address_books_updated' => 0,
            'address_books_deleted' => 0,
            'cards_created' => 0,
            'cards_updated' => 0,
            'cards_deleted' => 0,
            'resources_skipped_invalid' => 0,
            'resources_skipped_owner' => count($entries) - count($processableEntries),
        ];

        $runRestore = function () use (
            $processableEntries,
            $normalizedMode,
            $dryRun,
            &$summary,
            &$warnings,
        ): void {
            $resolvedOwnerIds = collect($processableEntries)
                ->pluck('resolved_owner_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $calendarUriPools = [];
            $addressBookUriPools = [];
            foreach ($resolvedOwnerIds as $ownerId) {
                $calendarUriPools[$ownerId] = $normalizedMode === 'replace'
                    ? []
                    : Calendar::query()
                        ->where('owner_id', $ownerId)
                        ->pluck('uri')
                        ->map(fn (string $uri): string => trim($uri))
                        ->filter()
                        ->values()
                        ->all();

                $addressBookUriPools[$ownerId] = $normalizedMode === 'replace'
                    ? []
                    : AddressBook::query()
                        ->where('owner_id', $ownerId)
                        ->pluck('uri')
                        ->map(fn (string $uri): string => trim($uri))
                        ->filter()
                        ->values()
                        ->all();
            }

            if ($normalizedMode === 'replace') {
                foreach ($resolvedOwnerIds as $ownerId) {
                    $calendars = Calendar::query()
                        ->where('owner_id', $ownerId)
                        ->get();
                    $calendarIds = $calendars
                        ->pluck('id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->all();
                    $addressBooks = AddressBook::query()
                        ->where('owner_id', $ownerId)
                        ->get();
                    $addressBookIds = $addressBooks
                        ->pluck('id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->all();

                    if ($calendarIds !== []) {
                        $summary['calendars_deleted'] += count($calendarIds);
                        $summary['calendar_objects_deleted'] += (int) CalendarObject::query()
                            ->whereIn('calendar_id', $calendarIds)
                            ->count();
                    }

                    if ($addressBookIds !== []) {
                        $summary['address_books_deleted'] += count($addressBookIds);
                        $summary['cards_deleted'] += (int) Card::query()
                            ->whereIn('address_book_id', $addressBookIds)
                            ->count();
                    }

                    if ($dryRun) {
                        continue;
                    }

                    foreach ($addressBooks as $addressBook) {
                        $this->resourceDeletion->deleteAddressBook($addressBook);
                    }

                    foreach ($calendars as $calendar) {
                        $this->resourceDeletion->deleteCalendar($calendar);
                    }
                }
            }

            /** @var array<string, array<int, string>> $calendarObjectUriPools */
            $calendarObjectUriPools = [];
            /** @var array<string, array<int, string>> $cardUriPools */
            $cardUriPools = [];
            /** @var array<string, int> $legacyCollectionUriCounts */
            $legacyCollectionUriCounts = [];

            foreach ($processableEntries as $entry) {
                $legacyUriCandidate = $this->collectionService->legacyCollectionUriCandidateFromStem((string) $entry['file_stem']);
                if (! is_string($legacyUriCandidate) || $legacyUriCandidate === '') {
                    continue;
                }

                $legacyKey = sprintf(
                    '%s|%d|%s',
                    (string) $entry['type'],
                    (int) $entry['resolved_owner_id'],
                    $legacyUriCandidate,
                );
                $legacyCollectionUriCounts[$legacyKey] = ($legacyCollectionUriCounts[$legacyKey] ?? 0) + 1;
            }

            foreach ($processableEntries as $entry) {
                $summary['files_processed']++;
                $resolvedOwnerId = (int) $entry['resolved_owner_id'];
                $legacyUriCandidate = $this->collectionService->legacyCollectionUriCandidateFromStem((string) $entry['file_stem']);
                $legacyKey = $legacyUriCandidate === null
                    ? null
                    : sprintf('%s|%d|%s', (string) $entry['type'], $resolvedOwnerId, $legacyUriCandidate);
                $allowLegacyUriMatch = $legacyKey !== null
                    && (($legacyCollectionUriCounts[$legacyKey] ?? 0) === 1);
                $collectionUri = isset($entry['collection_uri']) && is_string($entry['collection_uri'])
                    ? trim((string) $entry['collection_uri'])
                    : null;
                if ($collectionUri === '') {
                    $collectionUri = null;
                }

                if ($entry['type'] === 'calendar') {
                    $this->calendarHandler->restoreEntry(
                        entry: $entry,
                        resolvedOwnerId: $resolvedOwnerId,
                        collectionUri: $collectionUri,
                        legacyUriCandidate: $legacyUriCandidate,
                        allowLegacyUriMatch: $allowLegacyUriMatch,
                        dryRun: $dryRun,
                        mode: $normalizedMode,
                        ownerUriPool: $calendarUriPools[$resolvedOwnerId],
                        calendarObjectUriPools: $calendarObjectUriPools,
                        summary: $summary,
                        warnings: $warnings,
                    );

                    continue;
                }

                $this->addressBookHandler->restoreEntry(
                    entry: $entry,
                    resolvedOwnerId: $resolvedOwnerId,
                    collectionUri: $collectionUri,
                    legacyUriCandidate: $legacyUriCandidate,
                    allowLegacyUriMatch: $allowLegacyUriMatch,
                    dryRun: $dryRun,
                    mode: $normalizedMode,
                    ownerUriPool: $addressBookUriPools[$resolvedOwnerId],
                    cardUriPools: $cardUriPools,
                    summary: $summary,
                    warnings: $warnings,
                );
            }
        };

        if ($dryRun) {
            $runRestore();
        } else {
            DB::transaction($runRestore);
        }

        $reason = $dryRun
            ? __('backups.dry_run_complete_scanned_files_invalid_skipped', [
                'files_processed' => $summary['files_processed'],
                'resources_skipped_invalid' => $summary['resources_skipped_invalid'],
            ])
            : __('backups.restore_complete_changed_counts', [
                'calendar_count' => $summary['calendars_created'] + $summary['calendars_updated'],
                'address_book_count' => $summary['address_books_created'] + $summary['address_books_updated'],
                'record_count' => $summary['calendar_objects_created']
                    + $summary['calendar_objects_updated']
                    + $summary['cards_created']
                    + $summary['cards_updated'],
            ]);

        return [
            'status' => 'success',
            'trigger' => $trigger,
            'mode' => $normalizedMode,
            'dry_run' => $dryRun,
            'reason' => $reason,
            'executed_at_utc' => now('UTC')->toIso8601String(),
            'manifest' => $manifest,
            'summary' => $summary,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
