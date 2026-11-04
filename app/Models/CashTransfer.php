<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransfer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'transfer_number',
        'from_account_id',
        'to_account_id',
        'currency_id',
        'amount',
        'transfer_date',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'transfer_date' => 'date',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'from_account_id'
        );
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'to_account_id'
        );
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
