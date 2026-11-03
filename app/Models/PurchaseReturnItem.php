<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_item_id',
        'product_id',
        'quantity',
        'unit_cost',
        'discount_amount',
        'line_total',
        'unit_cost_afn',
        'line_total_afn',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'discount_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost_afn' => 'decimal:6',
            'line_total_afn' => 'decimal:4',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}