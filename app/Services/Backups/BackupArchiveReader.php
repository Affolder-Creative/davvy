<?php

namespace App\Services\Backups;

use RuntimeException;
use ZipArchive;

class BackupArchiveReader
{
    /**
     * Returns archive entries.
     *
     * @param  array<int, string>  $warnings
     * @return array{
     *   0:array<int, array{
     *     type:'calendar'|'address_book',
     *     archive_path:string,
     *     owner_id:int,
     *     file_stem:string,
     *     collection_uri:?string,
     *     contents:string
     *   }>,
     *   1:array<string, mixed>|null,
     *   2:array<int, int>
     * }
     */
    public function readArchiveEntries(string $archivePath, array &$warnings): array
    {
        $zip = new ZipArchive;
        $opened = $zip->open($archivePath);
        if ($opened !== true) {
            throw new RuntimeException(__('backups.unable_to_open_backup_archive'));
        }

        $entries = [];
        $ownerIds = [];
        $manifest = null;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = $zip->getNameIndex($index);
                if (! is_string($entryName) || $entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                if ($entryName === 'manifest.json') {
                    $manifestPayload = $zip->getFromIndex($index);
                    if (is_string($manifestPayload)) {
                        $decoded = json_decode($manifestPayload, true);
                        if (is_array($decoded)) {
                            $manifest = $decoded;
                        } else {
                            $warnings[] = __('backups.manifest_exists_but_invalid_json');
                        }
                    }

                    continue;
                }

                $matchType = null;
                $ownerId = null;
                $fileStem = null;
                if (preg_match('#^calendars/user-(\d+)/([^/]+)\.ics$#i', $entryName, $matches) === 1) {
                    $matchType = 'calendar';
                    $ownerId = (int) $matches[1];
                    $fileStem = (string) $matches[2];
                } elseif (preg_match('#^address-books/user-(\d+)/([^/]+)\.vcf$#i', $entryName, $matches) === 1) {
                    $matchType = 'address_book';
                    $ownerId = (int) $matches[1];
                    $fileStem = (string) $matches[2];
                } else {
                    continue;
                }

                $contents = $zip->getFromIndex($index);
                if (! is_string($contents)) {
                    $warnings[] = sprintf('Skipping unreadable archive entry "%s".', $entryName);

                    continue;
                }

                $ownerIds[] = $ownerId;
                $entries[] = [
                    'type' => $matchType,
                    'archive_path' => $entryName,
                    'owner_id' => $ownerId,
                    'file_stem' => $fileStem,
                    'collection_uri' => null,
                    'contents' => $contents,
                ];
            }
        } finally {
            $zip->close();
        }

        $collectionUriMap = $this->collectionUriMapFromManifest($manifest);
        if ($collectionUriMap !== []) {
            foreach ($entries as &$entry) {
                $manifestKey = (string) $entry['type'].'|'.(string) $entry['archive_path'];
                if (isset($collectionUriMap[$manifestKey])) {
                    $entry['collection_uri'] = $collectionUriMap[$manifestKey];
                }
            }
            unset($entry);
        }

        $ownerIds = array_values(array_unique($ownerIds));
        sort($ownerIds);

        return [$entries, $manifest, $ownerIds];
    }

    /**
     * Returns collection URI map from manifest.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, string>
     */
    private function collectionUriMapFromManifest(?array $manifest): array
    {
        if (! is_array($manifest)) {
            return [];
        }

        $collections = $manifest['collections'] ?? null;
        if (! is_array($collections)) {
            return [];
        }

        $map = [];
        foreach ([
            'calendars' => 'calendar',
            'address_books' => 'address_book',
        ] as $manifestKey => $entryType) {
            $items = $collections[$manifestKey] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $archivePath = (isset($item['archive_path']) && is_string($item['archive_path']))
                    ? trim($item['archive_path'])
                    : '';
                $uri = (isset($item['uri']) && is_string($item['uri']))
                    ? trim($item['uri'])
                    : '';
                if ($archivePath === '' || $uri === '') {
                    continue;
                }

                $map[$entryType.'|'.$archivePath] = $uri;
            }
        }

        return $map;
    }
}
