<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Models\CalendarObject;
use App\Models\Card;
use App\Models\ResourceShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileReviewQueueQaSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_seeds_mobile_review_queue_fixture_and_is_idempotent(): void
    {
        $this->artisan('app:qa:seed-mobile-review-queue', [
            '--force' => true,
            '--owner-email' => 'owner.qa@example.test',
            '--editor-email' => 'editor.qa@example.test',
            '--observer-email' => 'observer.qa@example.test',
            '--observer-permission' => SharePermission::ReadOnly->value,
        ])->assertExitCode(0);

        $owner = User::query()->where('email', 'owner.qa@example.test')->first();
        $editor = User::query()->where('email', 'editor.qa@example.test')->first();
        $observer = User::query()->where('email', 'observer.qa@example.test')->first();

        $this->assertNotNull($owner);
        $this->assertNotNull($editor);
        $this->assertNotNull($observer);

        $this->assertSame(Role::Admin, $owner->role);
        $this->assertSame(Role::Regular, $editor->role);
        $this->assertSame(Role::Regular, $observer->role);
        $this->assertTrue((bool) $owner->is_approved);
        $this->assertTrue((bool) $editor->is_approved);
        $this->assertTrue((bool) $observer->is_approved);

        $addressBook = AddressBook::query()
            ->where('owner_id', $owner->id)
            ->where('uri', 'rq-shared-contacts')
            ->first();
        $calendar = Calendar::query()
            ->where('owner_id', $owner->id)
            ->where('uri', 'rq-shared-calendar')
            ->first();

        $this->assertNotNull($addressBook);
        $this->assertNotNull($calendar);
        $this->assertTrue((bool) $addressBook->is_sharable);
        $this->assertTrue((bool) $calendar->is_sharable);

        $editorBookShare = ResourceShare::query()
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('resource_id', $addressBook->id)
            ->where('shared_with_id', $editor->id)
            ->first();
        $observerBookShare = ResourceShare::query()
            ->where('resource_type', ShareResourceType::AddressBook)
            ->where('resource_id', $addressBook->id)
            ->where('shared_with_id', $observer->id)
            ->first();
        $editorCalendarShare = ResourceShare::query()
            ->where('resource_type', ShareResourceType::Calendar)
            ->where('resource_id', $calendar->id)
            ->where('shared_with_id', $editor->id)
            ->first();
        $observerCalendarShare = ResourceShare::query()
            ->where('resource_type', ShareResourceType::Calendar)
            ->where('resource_id', $calendar->id)
            ->where('shared_with_id', $observer->id)
            ->first();

        $this->assertNotNull($editorBookShare);
        $this->assertNotNull($observerBookShare);
        $this->assertNotNull($editorCalendarShare);
        $this->assertNotNull($observerCalendarShare);

        $this->assertSame(SharePermission::Editor, $editorBookShare->permission);
        $this->assertSame(SharePermission::Editor, $editorCalendarShare->permission);
        $this->assertSame(SharePermission::ReadOnly, $observerBookShare->permission);
        $this->assertSame(SharePermission::ReadOnly, $observerCalendarShare->permission);

        $card = Card::query()
            ->where('address_book_id', $addressBook->id)
            ->where('uri', 'rq-test-person.vcf')
            ->first();
        $event = CalendarObject::query()
            ->where('calendar_id', $calendar->id)
            ->where('uri', 'rq-calendar-control-event.ics')
            ->first();

        $this->assertNotNull($card);
        $this->assertNotNull($event);
        $this->assertSame('rq-test-person-uid', $card->uid);
        $this->assertSame('rq-calendar-control-event-uid', $event->uid);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'owner_share_management_enabled',
            'value' => 'true',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'contact_management_enabled',
            'value' => 'true',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'contact_change_moderation_enabled',
            'value' => 'true',
        ]);

        $this->artisan('app:qa:seed-mobile-review-queue', [
            '--force' => true,
            '--owner-email' => 'owner.qa@example.test',
            '--editor-email' => 'editor.qa@example.test',
            '--observer-email' => 'observer.qa@example.test',
            '--observer-permission' => SharePermission::Editor->value,
        ])->assertExitCode(0);

        $this->assertSame(
            1,
            AddressBook::query()->where('owner_id', $owner->id)->where('uri', 'rq-shared-contacts')->count()
        );
        $this->assertSame(
            1,
            Calendar::query()->where('owner_id', $owner->id)->where('uri', 'rq-shared-calendar')->count()
        );
        $this->assertSame(
            1,
            Card::query()->where('address_book_id', $addressBook->id)->where('uid', 'rq-test-person-uid')->count()
        );
        $this->assertSame(
            1,
            CalendarObject::query()->where('calendar_id', $calendar->id)->where('uid', 'rq-calendar-control-event-uid')->count()
        );

        $observerBookShare->refresh();
        $observerCalendarShare->refresh();
        $this->assertSame(SharePermission::Editor, $observerBookShare->permission);
        $this->assertSame(SharePermission::Editor, $observerCalendarShare->permission);
    }
}
