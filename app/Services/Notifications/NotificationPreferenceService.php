<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NotificationPreferenceService
{
    public function isWebPushEnabled(): bool
    {
        return (bool) config('services.webpush.enabled', false);
    }

    public function hasServerConfiguration(): bool
    {
        return $this->publicKey() !== ''
            && trim((string) config('webpush.vapid.private_key', '')) !== ''
            && trim((string) config('webpush.vapid.subject', '')) !== '';
    }

    public function isAvailable(): bool
    {
        return $this->isWebPushEnabled() && $this->hasServerConfiguration();
    }

    public function publicKey(): string
    {
        return trim((string) config('webpush.vapid.public_key', ''));
    }

    /**
     * @return array<string, bool>
     */
    public function preferencesFor(User $user): array
    {
        $preference = $user->notificationPreference()->first();

        return $this->serializePreference($user, $preference);
    }

    public function ensureDefaultsFor(User $user): UserNotificationPreference
    {
        return $user->notificationPreference()->firstOrCreate([], [
            'review_queue_enabled' => true,
            'admin_pending_registration_enabled' => true,
            'admin_backup_operations_enabled' => true,
        ]);
    }

    /**
     * @param  array<string, bool>  $values
     * @return array<string, bool>
     */
    public function update(User $user, array $values): array
    {
        $preference = $this->ensureDefaultsFor($user);

        $updates = [];
        foreach ([
            'review_queue_enabled',
            'admin_pending_registration_enabled',
            'admin_backup_operations_enabled',
        ] as $key) {
            if (array_key_exists($key, $values)) {
                $updates[$key] = (bool) $values[$key];
            }
        }

        if ($updates !== []) {
            $preference->update($updates);
            $preference->refresh();
        }

        return $this->serializePreference($user, $preference);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return Collection<int, User>
     */
    public function reviewQueueRecipients(array $userIds): Collection
    {
        return $this->recipients('review_queue_enabled', function ($query) use ($userIds): void {
            $query->whereIn('id', $userIds);
        });
    }

    /**
     * @return Collection<int, User>
     */
    public function adminPendingRegistrationRecipients(): Collection
    {
        return $this->recipients('admin_pending_registration_enabled', function ($query): void {
            $query->where('role', 'admin');
        });
    }

    /**
     * @return Collection<int, User>
     */
    public function adminBackupOperationRecipients(): Collection
    {
        return $this->recipients('admin_backup_operations_enabled', function ($query): void {
            $query->where('role', 'admin');
        });
    }

    /**
     * @param  callable(Builder<User>): void  $scope
     * @return Collection<int, User>
     */
    private function recipients(string $preferenceColumn, callable $scope): Collection
    {
        if (! $this->isAvailable()) {
            return new Collection;
        }

        $query = User::query()
            ->whereHas('pushSubscriptions')
            ->whereHas('notificationPreference', function ($preferenceQuery) use ($preferenceColumn): void {
                $preferenceQuery->where($preferenceColumn, true);
            });

        $scope($query);

        return $query->get();
    }

    /**
     * @return array<string, bool>
     */
    private function serializePreference(User $user, ?UserNotificationPreference $preference): array
    {
        $isAdmin = $user->isAdmin();

        return [
            'review_queue_enabled' => (bool) ($preference?->review_queue_enabled ?? false),
            'admin_pending_registration_enabled' => $isAdmin
                ? (bool) ($preference?->admin_pending_registration_enabled ?? false)
                : false,
            'admin_backup_operations_enabled' => $isAdmin
                ? (bool) ($preference?->admin_backup_operations_enabled ?? false)
                : false,
        ];
    }
}
