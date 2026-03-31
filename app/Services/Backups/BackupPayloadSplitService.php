<?php

namespace App\Services\Backups;

use App\Services\Dav\VCardValidator;
use Illuminate\Support\Str;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class BackupPayloadSplitService
{
    public function __construct(
        private readonly VCardValidator $vCardValidator,
    ) {}

    /**
     * Returns split calendar payload.
     *
     * @return array<int, array{uri_candidate:string,payload:string}>
     */
    public function splitCalendarPayload(string $payload, string $archivePath): array
    {
        $component = Reader::read($payload);
        if (! $component instanceof VCalendar) {
            throw new RuntimeException(__('backups.entry_missing_vcalendar_payload', ['path' => $archivePath]));
        }

        $timezones = [];
        foreach ($component->select('VTIMEZONE') as $timezoneComponent) {
            if ($timezoneComponent instanceof Component) {
                $timezones[] = clone $timezoneComponent;
            }
        }

        $primaryComponents = [];
        foreach (['VEVENT', 'VTODO', 'VJOURNAL'] as $type) {
            foreach ($component->select($type) as $child) {
                if ($child instanceof Component) {
                    $primaryComponents[] = clone $child;
                }
            }
        }

        if ($primaryComponents === []) {
            return [];
        }

        $groups = [];
        $counter = 1;
        foreach ($primaryComponents as $child) {
            $uid = trim((string) ($child->UID ?? ''));
            $groupKey = $uid !== '' ? mb_strtolower($uid) : 'item-'.$counter;
            $counter++;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'uid' => $uid !== '' ? $uid : null,
                    'components' => [],
                ];
            }

            $groups[$groupKey]['components'][] = $child;
        }

        $resources = [];
        $groupIndex = 1;
        foreach ($groups as $group) {
            $resourceCalendar = new VCalendar([
                'VERSION' => '2.0',
                'PRODID' => '-//Davvy//Backup Restore//EN',
            ]);

            foreach ($timezones as $timezoneComponent) {
                $resourceCalendar->add(clone $timezoneComponent);
            }

            foreach ($group['components'] as $child) {
                $resourceCalendar->add(clone $child);
            }

            $uid = is_string($group['uid']) ? trim($group['uid']) : '';
            $stem = $uid !== ''
                ? (Str::slug($uid) !== '' ? Str::slug($uid) : 'item-'.substr(sha1($uid), 0, 12))
                : 'item-'.$groupIndex;

            $resources[] = [
                'uri_candidate' => $stem.'.ics',
                'payload' => $resourceCalendar->serialize(),
            ];
            $groupIndex++;
        }

        return $resources;
    }

    /**
     * Returns split address book payload.
     *
     * @return array<int, array{uri_candidate:string,payload:string}>
     */
    public function splitAddressBookPayload(string $payload, string $archivePath): array
    {
        $resources = [];

        preg_match_all('/BEGIN:VCARD[\s\S]*?END:VCARD/iu', $payload, $matches);
        $cards = is_array($matches[0] ?? null) ? $matches[0] : [];

        if ($cards === []) {
            throw new RuntimeException(__('backups.entry_missing_vcard_payloads', ['path' => $archivePath]));
        }

        $index = 1;
        foreach ($cards as $cardPayload) {
            $trimmed = trim((string) $cardPayload);
            if ($trimmed === '') {
                continue;
            }

            $normalizedPayload = $trimmed."\r\n";
            $uid = $this->vCardValidator->extractUid($normalizedPayload);
            $stem = $uid !== null && trim($uid) !== ''
                ? (Str::slug($uid) !== '' ? Str::slug($uid) : 'card-'.substr(sha1($uid), 0, 12))
                : 'card-'.$index;

            $resources[] = [
                'uri_candidate' => $stem.'.vcf',
                'payload' => $normalizedPayload,
            ];
            $index++;
        }

        return $resources;
    }
}
