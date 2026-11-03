<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerTransaction extends Model
{
    protected $fillable = [
        'business_partner_id',
        'currency_id',
        'created_by',
        'transaction_type',
        'direction',
        'amount',
        'reference_type',
        'reference_id',
        'transaction_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'transaction_date' => 'datetime',
        ];
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class);
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


