<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_number',
        'business_partner_id',
        'warehouse_id',
        'currency_id',
        'created_by',
        'purchase_date',
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
            'purchase_date' => 'date',
            'exchange_rate_to_afn' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'grand_total_afn' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
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
        return $this->hasMany(PurchaseItem::class);
    }
}