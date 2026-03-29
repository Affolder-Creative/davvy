<?php

namespace App\Services\Backups;

use App\Enums\ShareResourceType;
use App\Models\CalendarObject;
use App\Services\Dav\DavSyncService;
use App\Services\Dav\IcsValidator;
use Throwable;

class BackupRestoreCalendarHandler
{
    public function __construct(
        private readonly BackupRestoreCollectionService $collectionService,
        private readonly BackupResourceUriService $resourceUriService,
        private readonly BackupPayloadSplitService $payloadSplitService,
        private readonly IcsValidator $icsValidator,
        private readonly DavSyncService $syncService,
    ) {}

    /**
     * Restores a single calendar entry from the archive.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $ownerUriPool
     * @param  array<string, array<int, string>>  $calendarObjectUriPools
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
        array &$calendarObjectUriPools,
        array &$summary,
        array &$warnings,
    ): void {
        $calendar = $this->collectionService->upsertCalendarCollection(
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

        $calendarKey = $calendar['id'] !== null
            ? 'calendar:'.$calendar['id']
            : 'calendar-dry-run:'.$resolvedOwnerId.':'.$calendar['uri'];
        $calendarObjectUriPools[$calendarKey] ??= $calendar['id'] !== null
            ? CalendarObject::query()
                ->where('calendar_id', (int) $calendar['id'])
                ->pluck('uri')
                ->map(fn (string $uri): string => trim($uri))
                ->filter()
                ->values()
                ->all()
            : [];

        try {
            $calendarResources = $this->payloadSplitService->splitCalendarPayload(
                payload: (string) $entry['contents'],
                archivePath: (string) $entry['archive_path'],
            );
        } catch (Throwable $throwable) {
            $warnings[] = sprintf(
                'Skipping calendar payload "%s": %s',
                $entry['archive_path'],
                $throwable->getMessage(),
            );
            $summary['resources_skipped_invalid']++;

            return;
        }

        if ($calendarResources === []) {
            $warnings[] = sprintf(
                'Skipping calendar payload "%s": no VEVENT/VTODO/VJOURNAL components found.',
                $entry['archive_path'],
            );
            $summary['resources_skipped_invalid']++;

            return;
        }

        foreach ($calendarResources as $resource) {
            try {
                $normalized = $this->icsValidator->validateAndNormalize(
                    (string) $resource['payload'],
                );
            } catch (Throwable $throwable) {
                $warnings[] = sprintf(
                    'Skipping invalid calendar object in "%s": %s',
                    $entry['archive_path'],
                    $throwable->getMessage(),
                );
                $summary['resources_skipped_invalid']++;

                continue;
            }

            $resourceUid = $normalized['uid']
                ?? 'legacy-calendar-'.sha1((string) $resource['uri_candidate']);
            $existingObject = null;

            if ($calendar['id'] !== null) {
                $existingObject = CalendarObject::query()
                    ->where('calendar_id', (int) $calendar['id'])
                    ->where('uid', $resourceUid)
                    ->first();

                if (! $existingObject) {
                    $fallbackUri = $this->resourceUriService->normalizeResourceUri(
                        candidate: (string) $resource['uri_candidate'],
                        extension: 'ics',
                        fallbackStem: 'item',
                    );

                    $existingObject = CalendarObject::query()
                        ->where('calendar_id', (int) $calendar['id'])
                        ->where('uri', $fallbackUri)
                        ->first();
                }
            }

            if ($existingObject) {
                $summary['calendar_objects_updated']++;

                if (! $dryRun) {
                    $existingObject->update([
                        'uid' => $resourceUid,
                        'etag' => md5($normalized['data']),
                        'size' => strlen($normalized['data']),
                        'component_type' => $normalized['component_type'],
                        'first_occurred_at' => $normalized['first_occurred_at'],
                        'last_occurred_at' => $normalized['last_occurred_at'],
                        'data' => $normalized['data'],
                    ]);

                    $this->syncService->recordModified(
                        ShareResourceType::Calendar,
                        (int) $calendar['id'],
                        (string) $existingObject->uri,
                    );
                }

                continue;
            }

            $resourceUri = $this->resourceUriService->nextUniqueResourceUri(
                candidate: (string) $resource['uri_candidate'],
                extension: 'ics',
                fallbackStem: 'item',
                uriPool: $calendarObjectUriPools[$calendarKey],
            );
            $summary['calendar_objects_created']++;

            if ($dryRun || $calendar['id'] === null) {
                continue;
            }

            CalendarObject::query()->create([
                'calendar_id' => (int) $calendar['id'],
                'uri' => $resourceUri,
                'uid' => $resourceUid,
                'etag' => md5($normalized['data']),
                'size' => strlen($normalized['data']),
                'component_type' => $normalized['component_type'],
                'first_occurred_at' => $normalized['first_occurred_at'],
                'last_occurred_at' => $normalized['last_occurred_at'],
                'data' => $normalized['data'],
            ]);

            $this->syncService->recordAdded(
                ShareResourceType::Calendar,
                (int) $calendar['id'],
                $resourceUri,
            );
        }
    }
}
