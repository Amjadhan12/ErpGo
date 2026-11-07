<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockItem extends Model
{
    protected $fillable = [
        'opening_stock_id',
        'product_id',
        'quantity',
        'unit_cost_afn',
        'total_cost_afn',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_afn' => 'decimal:6',
            'total_cost_afn' => 'decimal:6',
        ];
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}