<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\FinancialAccount;
use Illuminate\Database\Seeder;
use RuntimeException;

class FinancialAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'currency_code' => 'AFN',
                'name' => 'Company Cash - AFN',
            ],
            [
                'currency_code' => 'USD',
                'name' => 'Company Cash - USD',
            ],
            [
                'currency_code' => 'PKR',
                'name' => 'Company Cash - PKR',
            ],
        ];

        foreach ($accounts as $accountData) {
            $currency = Currency::where(
                'code',
                $accountData['currency_code']
            )->first();

            if (! $currency) {
                throw new RuntimeException(
                    "Currency {$accountData['currency_code']} not found. Run CurrencySeeder first."
                );
            }

            FinancialAccount::updateOrCreate(
                [
                    'currency_id' => $currency->id,
                    'name' => $accountData['name'],
                ],
                [
                    'account_type' => 'cash',
                    'holder_type' => 'company',
                    'location' => null,
                    'account_number' => null,
                    'notes' => 'Main company cash account.',
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info(
            'Company cash accounts created or updated successfully.'
        );
    }
}