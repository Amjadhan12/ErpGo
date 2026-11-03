<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable = [
        'return_number',
        'sale_id',
        'business_partner_id',
        'warehouse_id',
        'currency_id',
        'created_by',
        'return_date',
        'exchange_rate_to_afn',
        'subtotal',
        'discount_amount',
        'grand_total',
        'grand_total_afn',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'exchange_rate_to_afn' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'grand_total_afn' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            BusinessPartner::class,
            'business_partner_id'
        );
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
    public function settlements(): HasMany
{
    return $this->hasMany(SaleReturnSettlement::class);
}
}