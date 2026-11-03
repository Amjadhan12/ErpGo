<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'movement_type',
        'quantity_in',
        'quantity_out',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'movement_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_in' => 'decimal:4',
            'quantity_out' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'movement_date' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}