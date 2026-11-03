<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'AFN',
                'name' => 'Afghan Afghani',
                'symbol' => '؋',
                'decimal_places' => 2,
                'is_base' => true,
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'PKR',
                'name' => 'Pakistani Rupee',
                'symbol' => 'Rs',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }

        $this->command?->info(
            'AFN, USD, and PKR currencies seeded successfully.'
        );
    }
}