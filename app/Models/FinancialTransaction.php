<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    protected $fillable = [
        'financial_account_id',
        'related_account_id',
        'created_by',
        'transaction_type',
        'direction',
        'amount',
        'exchange_rate',
        'transaction_group',
        'reference_type',
        'reference_id',
        'transaction_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'transaction_date' => 'datetime',
        ];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function relatedAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'related_account_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}