<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddressBookPrivateWorkingSetConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'private_address_book_id',
        'enabled',
        'hide_shared',
    ];

    /**
     * Returns casts.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'hide_shared' => 'boolean',
        ];
    }

    /**
     * Returns user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Returns private address book.
     */
    public function privateAddressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class, 'private_address_book_id');
    }

    /**
     * Returns selected source rows.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(AddressBookPrivateWorkingSetSource::class, 'config_id');
    }
}
