<?php

namespace Database\Seeders;

use App\Enums\ReferralSettingType;
use App\Models\ReferralSetting;
use Illuminate\Database\Seeder;

class ReferralSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key'         => 'commission_percentage',
                'value'       => '10',
                'description' => 'Commission percentage paid to the referrer when the referred user completes their first subscription payment.',
                'type'        => ReferralSettingType::Percentage,
            ],
            [
                'key'         => 'min_payout_amount',
                'value'       => '50000',
                'description' => 'Minimum accumulated earnings (in IDR) required before a user can request a commission payout.',
                'type'        => ReferralSettingType::Amount,
            ],
            [
                'key'         => 'payout_method',
                'value'       => 'manual',
                'description' => 'How payouts are processed: "manual" = admin triggers each payout; "auto" = system processes automatically.',
                'type'        => ReferralSettingType::StringType,
            ],
            [
                'key'         => 'referral_bonus',
                'value'       => '0',
                'description' => 'Optional one-time bonus amount (IDR) credited to the referred user upon completing their first payment. Set to 0 to disable.',
                'type'        => ReferralSettingType::Amount,
            ],
            [
                'key'         => 'auto_credit_commission',
                'value'       => 'true',
                'description' => 'Automatically credit commissions when a referred user\'s payment is verified (true), or require manual admin action (false).',
                'type'        => ReferralSettingType::Boolean,
            ],
        ];

        foreach ($defaults as $setting) {
            ReferralSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value'       => $setting['value'],
                    'description' => $setting['description'],
                    'type'        => $setting['type'],
                ],
            );
        }
    }
}
