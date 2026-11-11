<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'user_id',
        'title_pa',
        'title_en',
        'phone1',
        'phone2',
        'image_path',
        'address',
        'bill_text',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
