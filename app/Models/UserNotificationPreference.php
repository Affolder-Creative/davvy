<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'review_queue_enabled',
        'admin_pending_registration_enabled',
        'admin_backup_operations_enabled',
    ];

    protected function casts(): array
    {
        return [
            'review_queue_enabled' => 'bool',
            'admin_pending_registration_enabled' => 'bool',
            'admin_backup_operations_enabled' => 'bool',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
