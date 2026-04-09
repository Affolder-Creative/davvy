<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressBookPrivateWorkingSetLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_address_book_id',
        'source_card_uri',
        'source_card_uid',
        'source_payload',
        'overridden_fields',
        'private_address_book_id',
        'private_card_id',
    ];

    /**
     * Returns casts.
     */
    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
            'overridden_fields' => 'array',
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
     * Returns private card.
     */
    public function privateCard(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'private_card_id');
    }
}
