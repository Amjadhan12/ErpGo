<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_number',
        'business_partner_id',
        'warehouse_id',
        'currency_id',
        'created_by',
        'sale_date',
        'invoice_number',
        'exchange_rate_to_afn',
        'subtotal',
        'discount_amount',
        'grand_total',
        'grand_total_afn',
        'payment_status',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'exchange_rate_to_afn' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'grand_total_afn' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'business_partner_id');
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
        return $this->hasMany(SaleItem::class);
    }
    

public function payments(): HasMany
{
    return $this->hasMany(SalePayment::class);
}

}


