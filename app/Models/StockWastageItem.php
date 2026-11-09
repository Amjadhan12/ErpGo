<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWastageItem extends Model
{
    protected $fillable = [
        'stock_wastage_id',
        'product_id',
        'quantity',
        'unit_cost_afn',
        'total_cost_afn',
        'reason',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
        'total_cost_afn' => 'decimal:6',
    ];

    public function stockWastage(): BelongsTo
    {
        return $this->belongsTo(StockWastage::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}