<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItemBatch extends Model
{
    protected $fillable = [
        'sale_return_item_id',
        'sale_item_batch_id',
        'product_batch_id',
        'quantity',
        'unit_cost_afn',
        'total_cost_afn',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
        'total_cost_afn' => 'decimal:4',
    ];

    public function saleReturnItem(): BelongsTo
    {
        return $this->belongsTo(SaleReturnItem::class);
    }

    public function saleItemBatch(): BelongsTo
    {
        return $this->belongsTo(SaleItemBatch::class);
    }

    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
