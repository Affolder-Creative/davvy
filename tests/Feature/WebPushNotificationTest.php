<?php

namespace Tests\Feature;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Contact;
use App\Models\ResourceShare;
use App\Models\User;
use App\Notifications\DavvyWebPushNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Notifications\WebPushDispatchService;
use App\Services\RegistrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.webpush.enabled', true);
        config()->set('webpush.vapid.public_key', 'public-test-key');
        config()->set('webpush.vapid.private_key', 'private-test-key');
        config()->set('webpush.vapid.subject', 'mailto:admin@example.test');
    }

    public function test_authenticated_user_can_manage_web_push_subscription_and_preferences(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/notifications/web-push')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('public_key', 'public-test-key')
            ->assertJsonPath('preferences.review_queue_enabled', false);

        $this->actingAs($admin)
            ->postJson('/api/notifications/web-push/subscriptions', [
                'endpoint' => 'https://push.example.test/subscription-1',
                'keys' => [
                    'p256dh' => 'p256dh-key',
                    'auth' => 'auth-token',
                ],
                'content_encoding' => 'aes128gcm',
            ])
            ->assertCreated()
            ->assertJsonPath('preferences.review_queue_enabled', true)
            ->assertJsonPath('preferences.admin_pending_registration_enabled', true)
            ->assertJsonPath('subscription_count', 1);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $admin->id,
            'endpoint' => 'https://push.example.test/subscription-1',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/notifications/web-push/preferences', [
                'review_queue_enabled' => false,
                'admin_backup_operations_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('preferences.review_queue_enabled', false)
            ->assertJsonPath('preferences.admin_backup_operations_enabled', false);

        $this->actingAs($admin)
            ->deleteJson('/api/notifications/web-push/subscriptions', [
                'endpoint' => 'https://push.example.test/subscription-1',
            ])
            ->assertOk()
            ->assertJsonPath('subscription_count', 0);

        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example.test/subscription-1',
        ]);
    }

    public function test_guests_cannot_manage_web_push_state(): void
    {
        $this->getJson('/api/notifications/web-push')->assertUnauthorized();
        $this->postJson('/api/notifications/web-push/subscriptions', [])->assertUnauthorized();
        $this->putJson('/api/notifications/web-push/preferences', [])->assertUnauthorized();
        $this->deleteJson('/api/notifications/web-push/subscriptions', [])->assertUnauthorized();
    }

    public function test_regular_user_cannot_update_admin_notification_preferences(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'regular');

        $this->actingAs($user)
            ->putJson('/api/notifications/web-push/preferences', [
                'admin_pending_registration_enabled' => false,
            ])
            ->assertForbidden();
    }

    public function test_review_queue_creation_notifies_assigned_owner_once(): void
    {
        Notification::fake();

        app(RegistrationSettingsService::class)->setContactManagementEnabled(true);
        app(RegistrationSettingsService::class)->setContactChangeModerationEnabled(true);

        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $unrelated = User::factory()->create();
        $this->subscribe($owner, 'owner');
        $this->subscribe($unrelated, 'unrelated');

        $book = AddressBook::factory()->create([
            'owner_id' => $owner->id,
            'is_sharable' => true,
        ]);

        ResourceShare::query()->create([
            'owner_id' => $owner->id,
            'shared_with_id' => $editor->id,
            'resource_type' => ShareResourceType::AddressBook,
            'resource_id' => $book->id,
            'permission' => SharePermission::Editor,
        ]);

        $created = $this->actingAs($owner)->postJson('/api/contacts', [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'address_book_ids' => [$book->id],
        ]);
        $created->assertCreated();
        $contactId = (int) $created->json('id');

        $this->actingAs($editor)->patchJson('/api/contacts/'.$contactId, [
            'first_name' => 'Jordan',
            'last_name' => 'Rivera',
            'address_book_ids' => [$book->id],
        ])->assertStatus(202);

        $this->actingAs($editor)->patchJson('/api/contacts/'.$contactId, [
            'first_name' => 'Jordan',
            'last_name' => 'Rivera',
            'address_book_ids' => [$book->id],
        ])->assertStatus(202);

        Notification::assertSentTo(
            $owner,
            DavvyWebPushNotification::class,
            fn (DavvyWebPushNotification $notification): bool => $notification->payload()['type'] === 'review_queue'
                && $notification->payload()['url'] === '/review-queue'
        );
        Notification::assertSentToTimes($owner, DavvyWebPushNotification::class, 1);
        Notification::assertNotSentTo($unrelated, DavvyWebPushNotification::class);

        $this->assertSame(1, Contact::query()->count());
    }

    public function test_pending_registration_notifies_opted_in_admins_when_approval_required(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $regular = User::factory()->create();
        $this->subscribe($admin, 'admin');
        $this->subscribe($regular, 'regular');

        app(RegistrationSettingsService::class)->setPublicRegistrationEnabled(true);
        app(RegistrationSettingsService::class)->setPublicRegistrationApprovalRequired(true);
        config()->set('onboarding.require_public_email_verification', false);

        $this->postJson('/api/auth/register', [
            'name' => 'Pending Person',
            'email' => 'pending@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertAccepted();

        Notification::assertSentTo(
            $admin,
            DavvyWebPushNotification::class,
            fn (DavvyWebPushNotification $notification): bool => $notification->payload()['type'] === 'pending_registration'
                && $notification->payload()['url'] === '/admin'
        );
        Notification::assertNotSentTo($regular, DavvyWebPushNotification::class);
    }

    public function test_backup_notifications_only_go_to_opted_in_admins(): void
    {
        Notification::fake();

        $enabledAdmin = User::factory()->admin()->create();
        $disabledAdmin = User::factory()->admin()->create();
        $regular = User::factory()->create();
        $this->subscribe($enabledAdmin, 'enabled-admin');
        $this->subscribe($disabledAdmin, 'disabled-admin');
        $this->subscribe($regular, 'regular');

        app(NotificationPreferenceService::class)->update($disabledAdmin, [
            'admin_backup_operations_enabled' => false,
        ]);

        app(WebPushDispatchService::class)->notifyBackupOperationFinished(
            operation: 'run',
            status: 'success',
            message: 'Backup completed successfully.',
        );

        Notification::assertSentTo(
            $enabledAdmin,
            DavvyWebPushNotification::class,
            fn (DavvyWebPushNotification $notification): bool => $notification->payload()['type'] === 'backup_run'
        );
        Notification::assertNotSentTo($disabledAdmin, DavvyWebPushNotification::class);
        Notification::assertNotSentTo($regular, DavvyWebPushNotification::class);
    }

    public function test_preflight_fails_when_web_push_is_enabled_without_vapid_values(): void
    {
        config()->set('services.webpush.enabled', true);
        config()->set('webpush.vapid.public_key', '');
        config()->set('webpush.vapid.private_key', '');
        config()->set('webpush.vapid.subject', '');

        $this->artisan('app:preflight')
            ->expectsOutput('Error: ENABLE_WEB_PUSH_NOTIFICATIONS=true requires VAPID_PUBLIC_KEY.')
            ->expectsOutput('Error: ENABLE_WEB_PUSH_NOTIFICATIONS=true requires VAPID_PRIVATE_KEY.')
            ->expectsOutput('Error: ENABLE_WEB_PUSH_NOTIFICATIONS=true requires VAPID_SUBJECT.')
            ->assertExitCode(1);
    }

    private function subscribe(User $user, string $suffix): void
    {
        $user->updatePushSubscription(
            endpoint: 'https://push.example.test/'.$suffix,
            key: 'p256dh-'.$suffix,
            token: 'auth-'.$suffix,
            contentEncoding: 'aes128gcm',
        );

        app(NotificationPreferenceService::class)->ensureDefaultsFor($user);
    }
}
