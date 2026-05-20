<?php

namespace App\Models;

use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    protected $fillable = [
        'external_id',
        'period_start',
        'period_end',
        'status',
        'processed_at',
        'processing_errors',
        'raw_payload',
        'idempotency_key',
        'processed_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'processed_at' => 'datetime',
        'processing_errors' => 'array',
        'raw_payload' => 'array',
    ];
}
