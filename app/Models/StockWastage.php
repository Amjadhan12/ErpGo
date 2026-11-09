<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockWastage extends Model
{
    protected $fillable = [
        'wastage_number',
        'warehouse_id',
        'created_by',
        'wastage_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'wastage_date' => 'date',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockWastageItem::class);
    }
}