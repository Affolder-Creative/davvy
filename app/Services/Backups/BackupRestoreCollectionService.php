<?php

namespace App\Services\Backups;

use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Services\Dav\DavSyncService;
use Illuminate\Support\Str;

class BackupRestoreCollectionService
{
    public function __construct(
        private readonly DavSyncService $syncService,
    ) {}

    /**
     * Returns upsert calendar collection.
     *
     * @param  array<int, string>  $uriPool
     * @param  array<string, int|null>  $summary
     * @return array{id:int|null,uri:string,display_name:string}
     */
    public function upsertCalendarCollection(
        int $ownerId,
        string $fileStem,
        ?string $collectionUri,
        ?string $legacyUriCandidate,
        bool $allowLegacyUriMatch,
        bool $dryRun,
        string $mode,
        array &$uriPool,
        array &$summary,
    ): array {
        [$uriBase, $displayName] = $this->collectionIdentityFromStem($fileStem, 'calendar', 'Calendar');
        if (is_string($collectionUri) && trim($collectionUri) !== '') {
            $uriBase = trim($collectionUri);
        }

        $existing = $mode === 'merge'
            ? Calendar::query()
                ->where('owner_id', $ownerId)
                ->where('uri', $uriBase)
                ->first()
            : null;

        if (
            ! $existing
            && $mode === 'merge'
            && $allowLegacyUriMatch
            && is_string($legacyUriCandidate)
            && $legacyUriCandidate !== ''
            && $legacyUriCandidate !== $uriBase
        ) {
            $existing = Calendar::query()
                ->where('owner_id', $ownerId)
                ->where('uri', $legacyUriCandidate)
                ->first();
        }

        if ($existing) {
            if ($existing->display_name !== $displayName) {
                $summary['calendars_updated']++;

                if (! $dryRun) {
                    $existing->update(['display_name' => $displayName]);
                }
            }

            if (! in_array($existing->uri, $uriPool, true)) {
                $uriPool[] = $existing->uri;
            }

            if (! $dryRun) {
                $this->syncService->ensureResource(ShareResourceType::Calendar, (int) $existing->id);
            }

            return [
                'id' => (int) $existing->id,
                'uri' => (string) $existing->uri,
                'display_name' => (string) $existing->display_name,
            ];
        }

        $nextUri = $this->nextUniqueCollectionUri($uriBase, $uriPool);
        $summary['calendars_created']++;

        if ($dryRun) {
            return [
                'id' => null,
                'uri' => $nextUri,
                'display_name' => $displayName,
            ];
        }

        $calendar = Calendar::query()->create([
            'owner_id' => $ownerId,
            'uri' => $nextUri,
            'display_name' => $displayName,
            'description' => null,
            'color' => null,
            'timezone' => null,
            'is_default' => false,
            'is_sharable' => false,
        ]);
        $this->syncService->ensureResource(ShareResourceType::Calendar, (int) $calendar->id);

        return [
            'id' => (int) $calendar->id,
            'uri' => $nextUri,
            'display_name' => $displayName,
        ];
    }

    /**
     * Returns upsert address book collection.
     *
     * @param  array<int, string>  $uriPool
     * @param  array<string, int|null>  $summary
     * @return array{id:int|null,uri:string,display_name:string}
     */
    public function upsertAddressBookCollection(
        int $ownerId,
        string $fileStem,
        ?string $collectionUri,
        ?string $legacyUriCandidate,
        bool $allowLegacyUriMatch,
        bool $dryRun,
        string $mode,
        array &$uriPool,
        array &$summary,
    ): array {
        [$uriBase, $displayName] = $this->collectionIdentityFromStem($fileStem, 'address-book', 'Address Book');
        if (is_string($collectionUri) && trim($collectionUri) !== '') {
            $uriBase = trim($collectionUri);
        }

        $existing = $mode === 'merge'
            ? AddressBook::query()
                ->where('owner_id', $ownerId)
                ->where('uri', $uriBase)
                ->first()
            : null;

        if (
            ! $existing
            && $mode === 'merge'
            && $allowLegacyUriMatch
            && is_string($legacyUriCandidate)
            && $legacyUriCandidate !== ''
            && $legacyUriCandidate !== $uriBase
        ) {
            $existing = AddressBook::query()
                ->where('owner_id', $ownerId)
                ->where('uri', $legacyUriCandidate)
                ->first();
        }

        if ($existing) {
            if ($existing->display_name !== $displayName) {
                $summary['address_books_updated']++;

                if (! $dryRun) {
                    $existing->update(['display_name' => $displayName]);
                }
            }

            if (! in_array($existing->uri, $uriPool, true)) {
                $uriPool[] = $existing->uri;
            }

            if (! $dryRun) {
                $this->syncService->ensureResource(ShareResourceType::AddressBook, (int) $existing->id);
            }

            return [
                'id' => (int) $existing->id,
                'uri' => (string) $existing->uri,
                'display_name' => (string) $existing->display_name,
            ];
        }

        $nextUri = $this->nextUniqueCollectionUri($uriBase, $uriPool);
        $summary['address_books_created']++;

        if ($dryRun) {
            return [
                'id' => null,
                'uri' => $nextUri,
                'display_name' => $displayName,
            ];
        }

        $addressBook = AddressBook::query()->create([
            'owner_id' => $ownerId,
            'uri' => $nextUri,
            'display_name' => $displayName,
            'description' => null,
            'is_default' => false,
            'is_sharable' => false,
        ]);
        $this->syncService->ensureResource(ShareResourceType::AddressBook, (int) $addressBook->id);

        return [
            'id' => (int) $addressBook->id,
            'uri' => $nextUri,
            'display_name' => $displayName,
        ];
    }

    /**
     * Returns legacy collection URI candidate from stem.
     */
    public function legacyCollectionUriCandidateFromStem(string $fileStem): ?string
    {
        $rawStem = trim($fileStem);
        if (preg_match('/^\d+-(.+)$/', $rawStem, $matches) !== 1) {
            return null;
        }

        $candidate = Str::slug((string) ($matches[1] ?? ''));

        return $candidate === '' ? null : $candidate;
    }

    /**
     * Returns collection identity from stem.
     *
     * @return array{0:string,1:string}
     */
    private function collectionIdentityFromStem(string $fileStem, string $fallbackUriStem, string $fallbackDisplayName): array
    {
        $rawStem = trim($fileStem);
        $displayStem = $rawStem;

        if (preg_match('/^\d+-(.+)$/', $rawStem, $matches) === 1) {
            $displayStem = (string) ($matches[1] ?? $displayStem);
        }

        // Keep numeric ID prefixes from archive stems in URI generation so
        // same-name collections from the same owner restore as distinct resources.
        $uri = Str::slug($rawStem);
        if ($uri === '') {
            $uri = $fallbackUriStem;
        }

        $displayName = Str::of($displayStem)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->value();
        if ($displayName === '') {
            $displayName = $fallbackDisplayName;
        }

        return [$uri, $displayName];
    }

    /**
     * Returns next unique collection URI.
     *
     * @param  array<int, string>  $uriPool
     */
    private function nextUniqueCollectionUri(string $baseUri, array &$uriPool): string
    {
        $seed = Str::slug($baseUri);
        if ($seed === '') {
            $seed = 'resource';
        }

        $candidate = $seed;
        $counter = 2;
        while (in_array($candidate, $uriPool, true)) {
            $candidate = $seed.'-'.$counter;
            $counter++;
        }

        $uriPool[] = $candidate;

        return $candidate;
    }
}
