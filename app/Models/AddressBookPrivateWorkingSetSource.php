<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressBookPrivateWorkingSetSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'config_id',
        'source_address_book_id',
    ];

    /**
     * Returns config.
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(AddressBookPrivateWorkingSetConfig::class, 'config_id');
    }

    /**
     * Returns source address book.
     */
    public function sourceAddressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class, 'source_address_book_id');
    }
}
