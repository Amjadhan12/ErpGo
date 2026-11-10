<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovementBatch extends Model
{
    protected $fillable = [
        'inventory_movement_id',
        'product_batch_id',
        'direction',
        'quantity',
        'unit_cost_afn',
        'total_cost_afn',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost_afn' => 'decimal:6',
        'total_cost_afn' => 'decimal:4',
    ];

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
