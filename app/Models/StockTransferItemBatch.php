<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItemBatch extends Model
{
    protected $fillable = [
        'stock_transfer_item_id',
        'source_product_batch_id',
        'destination_product_batch_id',
        'quantity',
        'unit_cost_afn',
        'total_cost_afn',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
        'total_cost_afn' => 'decimal:4',
    ];

    public function stockTransferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class);
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(
            ProductBatch::class,
            'source_product_batch_id'
        );
    }

    public function destinationBatch(): BelongsTo
    {
        return $this->belongsTo(
            ProductBatch::class,
            'destination_product_batch_id'
        );
    }
}
