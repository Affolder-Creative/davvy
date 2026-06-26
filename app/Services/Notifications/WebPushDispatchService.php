<?php

namespace App\Services\Notifications;

use App\Notifications\DavvyWebPushNotification;
use Illuminate\Support\Facades\Notification;

class WebPushDispatchService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationCountService $counts,
    ) {}

    /**
     * @param  array<int, int>  $ownerIds
     */
    public function notifyReviewQueueCreated(array $ownerIds, int $createdCount): void
    {
        $normalizedOwnerIds = collect($ownerIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedOwnerIds === [] || $createdCount <= 0) {
            return;
        }

        $recipients = $this->preferences->reviewQueueRecipients($normalizedOwnerIds);

        foreach ($recipients as $recipient) {
            $count = $this->counts->reviewQueueCount($recipient);
            $body = $createdCount === 1
                ? 'A contact change is waiting for your review.'
                : $createdCount.' contact changes are waiting for your review.';

            $recipient->notify(new DavvyWebPushNotification(
                type: 'review_queue',
                title: 'Davvy Review Queue',
                body: $body,
                url: '/review-queue',
                tag: 'davvy-review-queue-'.$recipient->id,
                badgeCount: $count,
            ));
        }
    }

    public function notifyPendingRegistrationCreated(): void
    {
        $recipients = $this->preferences->adminPendingRegistrationRecipients();
        $pendingCount = $this->counts->pendingRegistrationCount();

        Notification::send($recipients, new DavvyWebPushNotification(
            type: 'pending_registration',
            title: 'New Davvy Registration',
            body: 'A new account is waiting for admin approval.',
            url: '/admin',
            tag: 'davvy-pending-registrations',
            badgeCount: $pendingCount,
        ));
    }

    public function notifyBackupOperationFinished(string $operation, string $status, string $message): void
    {
        $recipients = $this->preferences->adminBackupOperationRecipients();
        $normalizedStatus = strtolower(trim($status)) === 'success' ? 'success' : 'failed';
        $title = $operation === 'restore'
            ? 'Davvy Backup Restore '.($normalizedStatus === 'success' ? 'Complete' : 'Failed')
            : 'Davvy Backup '.($normalizedStatus === 'success' ? 'Complete' : 'Failed');

        Notification::send($recipients, new DavvyWebPushNotification(
            type: 'backup_'.$operation,
            title: $title,
            body: $message !== '' ? $message : 'Backup operation finished.',
            url: '/admin',
            tag: 'davvy-backup-'.$operation,
            badgeCount: 0,
        ));
    }
}
