<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessPartner extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'partner_type',
        'name',
        'phone',
        'email',
        'address',
        'business_name',
        'tax_number',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function openingBalances(): HasMany
    {
        return $this->hasMany(PartnerOpeningBalance::class);
    }
    public function transactions(): HasMany
{
    return $this->hasMany(PartnerTransaction::class);
}
public function user(): HasOne
{
    return $this->hasOne(User::class);
}
}