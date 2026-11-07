<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class OpeningStock extends Model
{
    protected $fillable = [
        'opening_number',
        'warehouse_id',
        'created_by',
        'opening_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
        ];
    }

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
        return $this->hasMany(OpeningStockItem::class);
    }
}