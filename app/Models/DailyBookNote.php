<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBookNote extends Model
{
    protected $fillable = [
        'title',
        'details',
        'note_date',
    ];

    protected $casts = [
        'note_date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}