<?php

namespace App\Http\Controllers;

use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\AddressBookPrivateWorkingSetService;
use App\Services\ResourceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class ExportController extends Controller
{
    public function __construct(
        private readonly ResourceAccessService $accessService,
        private readonly AddressBookPrivateWorkingSetService $privateWorkingSetService,
    ) {}

    /**
     * Returns export all calendars.
     */
    public function exportAllCalendars(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $skippedMalformedObjects = 0;
        $calendarCount = 0;

        $response = $this->downloadZip(
            addEntries: function (ZipArchive $zip, array &$usedNames) use ($user, &$skippedMalformedObjects, &$calendarCount): bool {
                $hasEntries = false;

                foreach ($this->readableCalendarsQuery($user)
                    ->orderBy('display_name')
                    ->orderBy('id')
                    ->cursor() as $calendar) {
                    $hasEntries = true;
                    $calendarCount++;
                    $calendarPayload = $this->buildCalendarPayload($calendar);
                    $skippedMalformedObjects += $calendarPayload['skipped_malformed_objects'];

                    $entryName = $this->uniqueArchiveEntryName(
                        $this->resourceFileName((string) $calendar->display_name, 'calendar', 'ics'),
                        $usedNames,
                    );

                    $zip->addFromString($entryName, $calendarPayload['payload']);
                }

                return $hasEntries;
            },
            emptyEntryName: 'calendars.txt',
            emptyEntryContents: "No calendars are available for export.\n",
            archiveName: $this->exportArchiveName('calendars')
        );

        $response->headers->set('X-Davvy-Skipped-Malformed-Objects', (string) $skippedMalformedObjects);
        $this->logMalformedCalendarExportSummary(
            scope: 'all-calendars',
            user: $user,
            skippedMalformedObjects: $skippedMalformedObjects,
            extraContext: [
                'calendar_count' => $calendarCount,
            ],
        );

        return $response;
    }

    /**
     * Returns export calendar.
     */
    public function exportCalendar(Request $request, Calendar $calendar): Response
    {
        $user = $request->user();

        if (! $this->accessService->userCanReadCalendar($user, $calendar)) {
            abort(403, __('contacts.cannot_access_calendar'));
        }

        $calendarPayload = $this->buildCalendarPayload($calendar);
        $this->logMalformedCalendarExportSummary(
            scope: 'single-calendar',
            user: $user,
            skippedMalformedObjects: $calendarPayload['skipped_malformed_objects'],
            extraContext: [
                'calendar_id' => (int) $calendar->id,
            ],
        );

        return response(
            $calendarPayload['payload'],
            200,
            [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => $this->attachmentHeader(
                    $this->resourceFileName($calendar->display_name, 'calendar', 'ics')
                ),
                'X-Davvy-Skipped-Malformed-Objects' => (string) $calendarPayload['skipped_malformed_objects'],
            ]
        );
    }

    /**
     * Returns export all address books.
     */
    public function exportAllAddressBooks(Request $request): BinaryFileResponse
    {
        return $this->downloadZip(
            addEntries: function (ZipArchive $zip, array &$usedNames) use ($request): bool {
                $hasEntries = false;

                foreach ($this->readableAddressBooksQuery($request->user())
                    ->orderBy('display_name')
                    ->orderBy('id')
                    ->cursor() as $addressBook) {
                    $hasEntries = true;

                    $entryName = $this->uniqueArchiveEntryName(
                        $this->resourceFileName((string) $addressBook->display_name, 'address-book', 'vcf'),
                        $usedNames,
                    );

                    $zip->addFromString($entryName, $this->buildAddressBookPayload($addressBook));
                }

                return $hasEntries;
            },
            emptyEntryName: 'address-books.txt',
            emptyEntryContents: "No address books are available for export.\n",
            archiveName: $this->exportArchiveName('address-books')
        );
    }

    /**
     * Returns export address book.
     */
    public function exportAddressBook(Request $request, AddressBook $addressBook): Response
    {
        $user = $request->user();

        if ($this->privateWorkingSetService->isQuarantinedPrivateAddressBookForUser($user, (int) $addressBook->id)) {
            abort(403, __('contacts.private_working_set_disabled_by_admins'));
        }

        if (! $this->accessService->userCanReadAddressBook($user, $addressBook)) {
            abort(403, __('contacts.cannot_access_address_book'));
        }

        return response(
            $this->buildAddressBookPayload($addressBook),
            200,
            [
                'Content-Type' => 'text/vcard; charset=utf-8',
                'Content-Disposition' => $this->attachmentHeader(
                    $this->resourceFileName($addressBook->display_name, 'address-book', 'vcf')
                ),
            ]
        );
    }

    /**
     * Returns readable calendars query.
     */
    private function readableCalendarsQuery(User $user): Builder
    {
        $sharedCalendarIds = ResourceShare::query()
            ->select('resource_id')
            ->where('shared_with_id', $user->id)
            ->where('resource_type', ShareResourceType::Calendar->value);

        return Calendar::query()
            ->where(function (Builder $query) use ($user, $sharedCalendarIds): void {
                $query
                    ->where('owner_id', $user->id)
                    ->orWhereIn('id', $sharedCalendarIds);
            });
    }

    /**
     * Returns readable address books query.
     */
    private function readableAddressBooksQuery(User $user): Builder
    {
        $sharedAddressBookIds = ResourceShare::query()
            ->select('resource_id')
            ->where('shared_with_id', $user->id)
            ->where('resource_type', ShareResourceType::AddressBook->value);

        $query = AddressBook::query()
            ->where(function (Builder $query) use ($user, $sharedAddressBookIds): void {
                $query
                    ->where('owner_id', $user->id)
                    ->orWhereIn('id', $sharedAddressBookIds);
            });

        $quarantinedPrivateIds = $this->privateWorkingSetService->quarantinedPrivateAddressBookIdsForUser($user);
        if ($quarantinedPrivateIds !== []) {
            $query->whereNotIn('id', $quarantinedPrivateIds);
        }

        return $query;
    }

    /**
     * Builds calendar payload.
     *
     * @return array{payload:string,skipped_malformed_objects:int}
     */
    private function buildCalendarPayload(Calendar $calendar): array
    {
        $export = new VCalendar([
            'VERSION' => '2.0',
            'PRODID' => '-//Davvy//Calendar Export//EN',
        ]);
        $skippedMalformedObjects = 0;

        $calendar->objects()
            ->orderBy('id')
            ->chunkById(250, function ($objects) use ($export, &$skippedMalformedObjects): void {
                foreach ($objects as $object) {
                    try {
                        $source = Reader::read($object->data);
                    } catch (Throwable $throwable) {
                        $skippedMalformedObjects++;

                        continue;
                    }

                    if (! $source instanceof VCalendar) {
                        continue;
                    }

                    foreach ($source->children() as $child) {
                        if ($child instanceof Component) {
                            $export->add(clone $child);
                        }
                    }
                }
            });

        return [
            'payload' => $export->serialize(),
            'skipped_malformed_objects' => $skippedMalformedObjects,
        ];
    }

    /**
     * Builds address book payload.
     */
    private function buildAddressBookPayload(AddressBook $addressBook): string
    {
        $payload = '';
        $isFirstCard = true;

        $addressBook->cards()
            ->orderBy('id')
            ->chunkById(500, function ($cards) use (&$payload, &$isFirstCard): void {
                foreach ($cards as $card) {
                    $normalized = rtrim((string) $card->data, "\r\n");
                    if ($normalized === '') {
                        continue;
                    }

                    if (! $isFirstCard) {
                        $payload .= "\r\n";
                    }

                    $payload .= $normalized;
                    $isFirstCard = false;
                }
            });

        return $payload;
    }

    /**
     * Returns download zip.
     *
     * @param  callable(ZipArchive, array<string, true>&): bool  $addEntries
     */
    private function downloadZip(
        callable $addEntries,
        string $emptyEntryName,
        string $emptyEntryContents,
        string $archiveName
    ): BinaryFileResponse {
        $tmpPath = tempnam(sys_get_temp_dir(), 'davvy-export-');

        if ($tmpPath === false) {
            abort(500, __('common.unable_to_create_temporary_export_file'));
        }

        $zip = new ZipArchive;
        $opened = $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            @unlink($tmpPath);
            abort(500, __('common.unable_to_create_export_archive'));
        }

        $usedNames = [];
        $hasEntries = $addEntries($zip, $usedNames);
        if (! $hasEntries) {
            $zip->addFromString($emptyEntryName, $emptyEntryContents);
        }

        $zip->close();

        return response()
            ->download($tmpPath, $archiveName, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Returns unique archive entry name.
     *
     * @param  array<string, true>  $usedNames
     */
    private function uniqueArchiveEntryName(string $name, array &$usedNames): string
    {
        $candidate = $name;
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = 1;

        while (isset($usedNames[$candidate])) {
            $candidate = $baseName.'-'.$suffix.($extension !== '' ? '.'.$extension : '');
            $suffix++;
        }

        $usedNames[$candidate] = true;

        return $candidate;
    }

    /**
     * Returns attachment header.
     */
    private function attachmentHeader(string $fileName): string
    {
        return sprintf('attachment; filename="%s"', $fileName);
    }

    /**
     * Returns resource file name.
     */
    private function resourceFileName(string $displayName, string $fallbackStem, string $extension): string
    {
        $stem = Str::slug($displayName);

        if ($stem === '') {
            $stem = $fallbackStem;
        }

        return $stem.'.'.$extension;
    }

    /**
     * Returns export archive name.
     */
    private function exportArchiveName(string $resourceType): string
    {
        return sprintf('davvy-%s-%s.zip', $resourceType, now()->format('Ymd-His'));
    }

    /**
     * Logs a calendar export summary when malformed objects are skipped.
     *
     * @param  array<string, int|string>  $extraContext
     */
    private function logMalformedCalendarExportSummary(
        string $scope,
        User $user,
        int $skippedMalformedObjects,
        array $extraContext = [],
    ): void {
        if ($skippedMalformedObjects <= 0) {
            return;
        }

        Log::warning('calendar_export_skipped_malformed_objects', array_merge([
            'scope' => $scope,
            'user_id' => (int) $user->id,
            'skipped_malformed_objects' => $skippedMalformedObjects,
        ], $extraContext));
    }
}
