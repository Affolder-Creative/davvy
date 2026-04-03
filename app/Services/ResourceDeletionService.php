<?php

namespace App\Services;

use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Services\Contacts\ContactMilestoneCalendarService;
use App\Services\Contacts\ManagedContactSyncService;
use Illuminate\Support\Facades\DB;

class ResourceDeletionService
{
    public function __construct(
        private readonly AddressBookMirrorService $mirrorService,
        private readonly ContactMilestoneCalendarService $milestoneCalendarService,
        private readonly ManagedContactSyncService $managedContactSync,
        private readonly ResourceShareCleanupService $shareCleanup,
    ) {}

    /**
     * Deletes address book.
     */
    public function deleteAddressBook(AddressBook $addressBook): void
    {
        $this->milestoneCalendarService->handleAddressBookDeleted($addressBook);
        $this->mirrorService->handleSourceAddressBookDeleted($addressBook->id);
        $this->managedContactSync->syncAddressBookDeleted($addressBook);
        $this->shareCleanup->deleteAddressBookShares($addressBook->id);

        $addressBook->delete();
        $this->deleteDavSyncRows(ShareResourceType::AddressBook, $addressBook->id);
    }

    /**
     * Deletes calendar.
     */
    public function deleteCalendar(Calendar $calendar): void
    {
        $calendar->loadMissing('milestoneSetting');
        if ($calendar->milestoneSetting) {
            $calendar->milestoneSetting->update([
                'enabled' => false,
                'calendar_id' => null,
            ]);
        }

        $this->shareCleanup->deleteCalendarShares($calendar->id);

        $calendar->delete();
        $this->deleteDavSyncRows(ShareResourceType::Calendar, $calendar->id);
    }

    /**
     * Deletes DAV sync rows for the given resource.
     */
    private function deleteDavSyncRows(ShareResourceType $resourceType, int $resourceId): void
    {
        DB::table('dav_resource_sync_changes')
            ->where('resource_type', $resourceType->value)
            ->where('resource_id', $resourceId)
            ->delete();

        DB::table('dav_resource_sync_states')
            ->where('resource_type', $resourceType->value)
            ->where('resource_id', $resourceId)
            ->delete();
    }
}
