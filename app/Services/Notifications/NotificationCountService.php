<?php

namespace App\Services\Notifications;

use App\Enums\ContactChangeStatus;
use App\Models\ContactChangeRequest;
use App\Models\User;

class NotificationCountService
{
    /**
     * @return array{review_queue:int,pending_registrations:int,total:int}
     */
    public function countsFor(User $user): array
    {
        $reviewQueue = $this->reviewQueueCount($user);
        $pendingRegistrations = $user->isAdmin() ? $this->pendingRegistrationCount() : 0;

        return [
            'review_queue' => $reviewQueue,
            'pending_registrations' => $pendingRegistrations,
            'total' => $reviewQueue + $pendingRegistrations,
        ];
    }

    public function reviewQueueCount(User $user): int
    {
        $query = ContactChangeRequest::query()
            ->whereIn('status', [
                ContactChangeStatus::Pending->value,
                ContactChangeStatus::ManualMergeNeeded->value,
            ]);

        if (! $user->isAdmin()) {
            $query->where('approval_owner_id', $user->id);
        }

        return (int) $query->count();
    }

    public function pendingRegistrationCount(): int
    {
        return (int) User::query()
            ->where('is_approved', false)
            ->count();
    }
}
