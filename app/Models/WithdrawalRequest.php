<?php

namespace App\Models;

use Database\Factories\WithdrawalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    /** @use HasFactory<WithdrawalRequestFactory> */
    use HasFactory;
    protected $fillable = [
        'wallet_id',
        'amount',
        'status',
        'bank_reference_id',
        'reserved_at',
        'confirmed_at',
        'failed_at',
    ];

    protected $casts = [
        'wallet_id' => 'integer',
        'amount' => 'decimal:4',
        'reserved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
