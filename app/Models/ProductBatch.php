<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBatch extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'batch_number',
        'expiry_date',
        'received_quantity',
        'remaining_quantity',
        'unit_cost_afn',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'received_quantity' => 'decimal:4',
        'remaining_quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function saleItemBatches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    public function movementBatches(): HasMany
    {
        return $this->hasMany(InventoryMovementBatch::class);
    }

    public function saleReturnItemBatches(): HasMany
    {
        return $this->hasMany(SaleReturnItemBatch::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(
            StockTransferItemBatch::class,
            'source_product_batch_id'
        );
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(
            StockTransferItemBatch::class,
            'destination_product_batch_id'
        );
    }
}
