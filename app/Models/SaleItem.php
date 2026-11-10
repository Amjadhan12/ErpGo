<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'line_total',
        'unit_price_afn',
        'line_total_afn',
        'unit_cost_afn',
        'total_cost_afn',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_price_afn' => 'decimal:6',
            'line_total_afn' => 'decimal:4',
            'unit_cost_afn' => 'decimal:6',
            'total_cost_afn' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    public function batchAllocations(): HasMany
{
    return $this->hasMany(SaleItemBatch::class);
}
}
