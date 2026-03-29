<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\BackupPayloadSplitService;
use RuntimeException;
use Tests\TestCase;

class BackupPayloadSplitServiceTest extends TestCase
{
    public function test_split_calendar_payload_groups_components_by_uid(): void
    {
        $service = app(BackupPayloadSplitService::class);

        $payload = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//EN
BEGIN:VEVENT
UID:event-1
DTSTAMP:20250101T000000Z
DTSTART:20250102T000000Z
SUMMARY:Event One
END:VEVENT
BEGIN:VEVENT
UID:event-1
DTSTAMP:20250101T000000Z
DTSTART:20250109T000000Z
SUMMARY:Event One (Update)
END:VEVENT
BEGIN:VTODO
UID:todo-1
DTSTAMP:20250101T000000Z
SUMMARY:Todo One
END:VTODO
END:VCALENDAR
ICS;

        $resources = $service->splitCalendarPayload($payload, 'calendars/user-1/test.ics');

        $this->assertCount(2, $resources);
        $this->assertSame(
            ['event-1.ics', 'todo-1.ics'],
            collect($resources)->pluck('uri_candidate')->sort()->values()->all(),
        );
    }

    public function test_split_address_book_payload_throws_when_no_vcards_exist(): void
    {
        $service = app(BackupPayloadSplitService::class);

        $this->expectException(RuntimeException::class);
        $service->splitAddressBookPayload('NOT_A_VCARD', 'address-books/user-1/test.vcf');
    }
}
