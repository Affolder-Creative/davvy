<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactPhotoUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'user_id',
        'contact_id',
        'disk',
        'path',
        'mime',
        'width',
        'height',
        'bytes',
        'sha256',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Returns casts.
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Returns uploader.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Returns contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
