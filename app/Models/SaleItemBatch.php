<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItemBatch extends Model
{
    protected $fillable = [
        'sale_item_id',
        'product_batch_id',
        'quantity',
        'returned_quantity',
        'unit_cost_afn',
        'total_cost_afn',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'returned_quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
        'total_cost_afn' => 'decimal:4',
    ];

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function returnBatches(): HasMany
    {
        return $this->hasMany(SaleReturnItemBatch::class);
    }
}
